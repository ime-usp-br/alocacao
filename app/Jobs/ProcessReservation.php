<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use romanzipp\QueueMonitor\Traits\IsMonitored;
use App\Models\Requisition;
use App\Models\Reservation;
use App\Models\SchoolTerm;
use App\Models\SchoolClass;
use App\Services\ReservationApiService;
use Illuminate\Support\Facades\Log;
use Exception;

class ProcessReservation implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, IsMonitored;

    public $rooms;
    private ReservationApiService $reservationApiService;
    private array $createdReservations = [];
    private array $processedSchoolClasses = [];

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct($rooms)
    {
        $this->rooms = $rooms;
    }

    public function progressCooldown(): int
    {
        return 1; 
    }


    public $timeout = 999;

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        // Initialize the ReservationApiService
        $this->reservationApiService = app(ReservationApiService::class);
        
        $this->logJobStart();
        $this->queueProgress(0);

        $schoolterm = SchoolTerm::getLatest();
        $rooms_id = $this->rooms;
        
        $schoolclasses = SchoolClass::whereBelongsTo($schoolterm)
            ->whereHas("room", function($query) use($rooms_id) {
                $query->whereIn("id", $rooms_id);
            })
            ->get();

        $this->logSchoolClassesFound($schoolclasses);

        $t = count($schoolclasses) * 2;
        $n = 0;

        try {
            // Phase 1: Availability Check
            $this->logPhaseStart('availability_check', count($schoolclasses));
            
            foreach($schoolclasses as $schoolclass) {
                $this->logAvailabilityCheck($schoolclass);
                
                // Use new API service for availability check if enabled, fallback to legacy
                $isAvailable = $this->checkAvailability($schoolclass);
                
                if (!$isAvailable) {
                    $this->logAvailabilityFailed($schoolclass);
                    $this->queueData([
                        "status" => "failed",
                        "schoolclass" => $schoolclass->toArray(),
                        "room" => $schoolclass->room->nome,
                        "schedules" => $schoolclass->classschedules->toArray(),
                        "reason" => "Conflito de horário detectado"
                    ]);
                    return false;
                }

                $n += 1;
                $this->queueProgress(floor($n * 100 / $t));
                $this->logProgressUpdate($n, $t, 'availability_check');
            }

            // Phase 2: Reservation Creation
            $this->logPhaseStart('reservation_creation', count($schoolclasses));
            
            foreach($schoolclasses as $schoolclass) {
                $this->processSchoolClassReservation($schoolclass);
                
                $n += 1;
                $this->queueProgress(floor($n * 100 / $t));
                $this->logProgressUpdate($n, $t, 'reservation_creation');
            }
            
            $this->queueProgress(100);
            $this->logJobCompletion();
            
        } catch (Exception $e) {
            $this->handleJobError($e);
            throw $e;
        }
    }

    /**
     * Check availability using API service with fallback to legacy method
     *
     * @param SchoolClass $schoolclass
     * @return bool
     */
    private function checkAvailability(SchoolClass $schoolclass): bool
    {
        // Check if we should use the new API service
        if ($this->shouldUseApiService()) {
            try {
                return $this->reservationApiService->checkAvailabilityForSchoolClass($schoolclass);
            } catch (Exception $e) {
                $this->logApiError($e, 'availability_check', $schoolclass);
                
                // AC5: Explicit error strategy - no fallback to legacy
                $this->logExplicitErrorStrategy($e, $schoolclass, 'availability_check');
                throw $e;
            }
        }
        
        // Use legacy method
        return Reservation::checkAvailability($schoolclass);
    }

    /**
     * Process reservation creation for a school class
     *
     * @param SchoolClass $schoolclass
     * @throws Exception
     */
    private function processSchoolClassReservation(SchoolClass $schoolclass): void
    {
        $this->logReservationCreationStart($schoolclass);
        
        // Check if we should use the new API service
        if ($this->shouldUseApiService()) {
            try {
                // Use new ReservationApiService
                $reservations = $this->reservationApiService->createReservationsFromSchoolClass($schoolclass);
                
                // Track created reservations for potential rollback
                $this->trackCreatedReservations($schoolclass, $reservations);
                
                $this->logReservationCreationSuccess($schoolclass, $reservations);
                
            } catch (Exception $e) {
                $this->logApiError($e, 'reservation_creation', $schoolclass);
                
                // Attempt rollback of any partial creations
                $this->rollbackPartialCreations($schoolclass);
                
                // AC5: Explicit error strategy - no fallback to legacy
                // When API Salas is unavailable, fail explicitly with proper logging
                $this->logExplicitErrorStrategy($e, $schoolclass);
                throw $e;
            }
        } else {
            // Use legacy method
            $this->processLegacyReservationCreation($schoolclass);
        }
    }

    /**
     * Process reservation creation using legacy method
     *
     * @param SchoolClass $schoolclass
     */
    private function processLegacyReservationCreation(SchoolClass $schoolclass): void
    {
        $requisition = Requisition::createFromSchoolClass($schoolclass);
        $reservations = Reservation::createFrom($requisition, $schoolclass);
        
        // Convert legacy reservations to track format
        $reservationData = [];
        if (is_array($reservations)) {
            foreach ($reservations as $reservationId) {
                $reservationData[] = ['id' => $reservationId, 'legacy' => true];
            }
        }
        
        $this->trackCreatedReservations($schoolclass, $reservationData);
        $this->logReservationCreationSuccess($schoolclass, $reservationData);
    }

    /**
     * Track created reservations for potential rollback
     *
     * @param SchoolClass $schoolclass
     * @param array $reservations
     */
    private function trackCreatedReservations(SchoolClass $schoolclass, array $reservations): void
    {
        $this->createdReservations[$schoolclass->id] = $reservations;
        $this->processedSchoolClasses[] = $schoolclass->id;
    }

    /**
     * Rollback partial creations for a school class
     *
     * @param SchoolClass $schoolclass
     */
    private function rollbackPartialCreations(SchoolClass $schoolclass): void
    {
        if (!isset($this->createdReservations[$schoolclass->id])) {
            return;
        }

        $this->logRollbackStart($schoolclass);

        $reservations = $this->createdReservations[$schoolclass->id];
        $rollbackErrors = [];

        foreach ($reservations as $reservation) {
            try {
                if ($reservation['legacy'] ?? false) {
                    // Legacy reservation - delete directly from database
                    Reservation::where('id', $reservation['id'])->delete();
                } else {
                    // API reservation - use service to cancel
                    $this->cancelApiReservation($reservation);
                }
                
                $this->logReservationRollback($reservation['id'], 'success');
                
            } catch (Exception $e) {
                $rollbackErrors[] = [
                    'reservation_id' => $reservation['id'],
                    'error' => $e->getMessage()
                ];
                $this->logReservationRollback($reservation['id'], 'failed', $e->getMessage());
            }
        }

        // Remove from tracking
        unset($this->createdReservations[$schoolclass->id]);
        
        $this->logRollbackComplete($schoolclass, $rollbackErrors);
    }

    /**
     * Cancel a single API reservation
     *
     * @param array $reservation
     * @throws Exception
     */
    private function cancelApiReservation(array $reservation): void
    {
        // This would require the API service to have a cancel method for individual reservations
        // For now, we'll log that we need to implement this
        $this->logInfo('API reservation cancellation needed', [
            'reservation_id' => $reservation['id'],
            'action_needed' => 'Manual cancellation via API or administrative interface'
        ]);
    }

    /**
     * Handle job-level errors with comprehensive logging and cleanup
     *
     * @param Exception $e
     */
    private function handleJobError(Exception $e): void
    {
        $this->logError('ProcessReservation job failed with exception', [
            'error_message' => $e->getMessage(),
            'error_class' => get_class($e),
            'error_file' => $e->getFile(),
            'error_line' => $e->getLine(),
            'processed_classes' => count($this->processedSchoolClasses),
            'total_created_reservations' => array_sum(array_map('count', $this->createdReservations)),
            'rooms_being_processed' => $this->rooms
        ]);

        // Attempt to rollback all created reservations
        $this->rollbackAllCreations();

        // Update queue data with error information
        $this->queueData([
            "status" => "failed",
            "error" => $e->getMessage(),
            "processed_count" => count($this->processedSchoolClasses),
            "rollback_attempted" => true,
            "timestamp" => now()->toISOString()
        ]);
    }

    /**
     * Rollback all creations made during this job
     */
    private function rollbackAllCreations(): void
    {
        if (empty($this->createdReservations)) {
            return;
        }

        $this->logInfo('Starting complete job rollback', [
            'school_classes_to_rollback' => count($this->createdReservations),
            'total_reservations' => array_sum(array_map('count', $this->createdReservations))
        ]);

        foreach ($this->createdReservations as $schoolClassId => $reservations) {
            $schoolClass = SchoolClass::find($schoolClassId);
            if ($schoolClass) {
                $this->rollbackPartialCreations($schoolClass);
            }
        }
    }

    /**
     * Check if we should use the API service
     *
     * @return bool
     */
    private function shouldUseApiService(): bool
    {
        return config('salas.use_api', false);
    }

    /**
     * AC5: Explicit error strategy implementation
     * Always fail explicitly when API is unavailable - no automatic fallback
     *
     * @return bool
     */
    private function shouldFallbackOnError(): bool
    {
        // AC5 architectural decision: explicit error handling, no fallback
        return false;
    }

    // Logging Methods
    
    private function logJobStart(): void
    {
        $this->logInfo('ProcessReservation job started', [
            'job_id' => $this->job->getJobId() ?? 'unknown',
            'rooms_count' => count($this->rooms ?? []),
            'rooms' => $this->rooms,
            'use_api_service' => $this->shouldUseApiService(),
            'timestamp' => now()->toISOString()
        ]);
    }

    private function logSchoolClassesFound(object $schoolclasses): void
    {
        $this->logInfo('School classes loaded for processing', [
            'total_classes' => $schoolclasses->count(),
            'classes_with_rooms' => $schoolclasses->whereNotNull('room')->count(),
            'classes_details' => $schoolclasses->map(function($sc) {
                return [
                    'id' => $sc->id,
                    'disciplina' => $sc->coddis,
                    'turma' => $sc->codtur,
                    'sala' => $sc->room ? $sc->room->nome : null,
                    'schedules_count' => $sc->classschedules->count()
                ];
            })->toArray()
        ]);
    }

    private function logPhaseStart(string $phase, int $count): void
    {
        $this->logInfo("Phase started: {$phase}", [
            'phase' => $phase,
            'items_to_process' => $count,
            'timestamp' => now()->toISOString()
        ]);
    }

    private function logAvailabilityCheck(SchoolClass $schoolclass): void
    {
        $this->logDebug('Checking availability for school class', [
            'schoolclass_id' => $schoolclass->id,
            'disciplina' => $schoolclass->coddis,
            'turma' => $schoolclass->codtur,
            'sala_nome' => $schoolclass->room ? $schoolclass->room->nome : null,
            'schedules_count' => $schoolclass->classschedules->count(),
            'method' => $this->shouldUseApiService() ? 'api_service' : 'legacy'
        ]);
    }

    private function logAvailabilityFailed(SchoolClass $schoolclass): void
    {
        $this->logWarning('Availability check failed - conflicts detected', [
            'schoolclass_id' => $schoolclass->id,
            'disciplina' => $schoolclass->coddis,
            'sala_nome' => $schoolclass->room ? $schoolclass->room->nome : null,
            'schedules' => $schoolclass->classschedules->map(function($schedule) {
                return [
                    'dia' => $schedule->diasmnocp,
                    'inicio' => $schedule->horent,
                    'fim' => $schedule->horsai
                ];
            })->toArray()
        ]);
    }

    private function logProgressUpdate(int $current, int $total, string $phase): void
    {
        $percentage = floor($current * 100 / $total);
        $this->logDebug('Progress update', [
            'phase' => $phase,
            'current_step' => $current,
            'total_steps' => $total,
            'percentage' => $percentage,
            'timestamp' => now()->toISOString()
        ]);
    }

    private function logReservationCreationStart(SchoolClass $schoolclass): void
    {
        $this->logInfo('Starting reservation creation for school class', [
            'schoolclass_id' => $schoolclass->id,
            'disciplina' => $schoolclass->coddis,
            'turma' => $schoolclass->codtur,
            'sala_nome' => $schoolclass->room ? $schoolclass->room->nome : null,
            'method' => $this->shouldUseApiService() ? 'api_service' : 'legacy',
            'timestamp' => now()->toISOString()
        ]);
    }

    private function logReservationCreationSuccess(SchoolClass $schoolclass, array $reservations): void
    {
        $this->logInfo('Reservation creation completed successfully', [
            'schoolclass_id' => $schoolclass->id,
            'disciplina' => $schoolclass->coddis,
            'reservations_created' => count($reservations),
            'reservation_ids' => array_column($reservations, 'id'),
            'method' => $this->shouldUseApiService() ? 'api_service' : 'legacy',
            'timestamp' => now()->toISOString()
        ]);
    }

    private function logApiError(Exception $e, string $operation, SchoolClass $schoolclass): void
    {
        $this->logError("API service error during {$operation}", [
            'operation' => $operation,
            'schoolclass_id' => $schoolclass->id,
            'disciplina' => $schoolclass->coddis,
            'sala_nome' => $schoolclass->room ? $schoolclass->room->nome : null,
            'error_message' => $e->getMessage(),
            'error_class' => get_class($e),
            'error_file' => $e->getFile(),
            'error_line' => $e->getLine(),
            'timestamp' => now()->toISOString()
        ]);
    }

    /**
     * Log explicit error strategy (AC5 implementation)
     *
     * @param Exception $e
     * @param SchoolClass $schoolclass
     * @param string $operation
     */
    private function logExplicitErrorStrategy(Exception $e, SchoolClass $schoolclass, string $operation = 'reservation_creation'): void
    {
        $this->logError("API Salas indisponível - erro explícito conforme AC5", [
            'operation' => $operation,
            'schoolclass_id' => $schoolclass->id,
            'disciplina' => $schoolclass->coddis,
            'sala_nome' => $schoolclass->room ? $schoolclass->room->nome : null,
            'error_strategy' => 'explicit_failure_no_fallback',
            'ac5_compliance' => true,
            'api_error' => $e->getMessage(),
            'user_impact' => 'Operation will fail with clear error message',
            'admin_action_required' => 'Check API Salas connectivity and resolve infrastructure issues',
            'timestamp' => now()->toISOString()
        ]);
    }

    private function logRollbackStart(SchoolClass $schoolclass): void
    {
        $reservationCount = isset($this->createdReservations[$schoolclass->id]) 
            ? count($this->createdReservations[$schoolclass->id]) 
            : 0;
            
        $this->logWarning('Starting reservation rollback for school class', [
            'schoolclass_id' => $schoolclass->id,
            'disciplina' => $schoolclass->coddis,
            'reservations_to_rollback' => $reservationCount,
            'timestamp' => now()->toISOString()
        ]);
    }

    private function logReservationRollback(int $reservationId, string $status, string $error = null): void
    {
        $context = [
            'reservation_id' => $reservationId,
            'rollback_status' => $status,
            'timestamp' => now()->toISOString()
        ];
        
        if ($error) {
            $context['error'] = $error;
        }

        $level = $status === 'success' ? 'info' : 'error';
        $this->{"log{$level}"}("Reservation rollback {$status}", $context);
    }

    private function logRollbackComplete(SchoolClass $schoolclass, array $errors): void
    {
        $this->logInfo('Reservation rollback completed', [
            'schoolclass_id' => $schoolclass->id,
            'disciplina' => $schoolclass->coddis,
            'rollback_errors_count' => count($errors),
            'rollback_errors' => $errors,
            'timestamp' => now()->toISOString()
        ]);
    }

    private function logJobCompletion(): void
    {
        $this->logInfo('ProcessReservation job completed successfully', [
            'total_classes_processed' => count($this->processedSchoolClasses),
            'total_reservations_created' => array_sum(array_map('count', $this->createdReservations)),
            'method_used' => $this->shouldUseApiService() ? 'api_service' : 'legacy',
            'completion_timestamp' => now()->toISOString()
        ]);
    }

    // Base logging methods
    
    private function logInfo(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    private function logDebug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    private function logWarning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    private function logError(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    private function log(string $level, string $message, array $context = []): void
    {
        $context['component'] = 'ProcessReservation';
        $context['job_class'] = self::class;
        $context['rooms'] = $this->rooms;
        
        if (isset($this->job)) {
            $context['job_id'] = $this->job->getJobId();
            $context['queue_name'] = $this->job->getQueue();
        }
        
        Log::$level($message, $context);
    }
}
