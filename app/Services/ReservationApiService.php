<?php

namespace App\Services;

use App\Models\SchoolClass;
use App\Models\Requisition;
use App\Services\SalasApiClient;
use App\Services\ReservationMapper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Exception;
use Carbon\Carbon;

class ReservationApiService
{
    private SalasApiClient $salasApiClient;
    private ReservationMapper $reservationMapper;
    private string $cachePrefix;

    public function __construct(SalasApiClient $salasApiClient, ReservationMapper $reservationMapper)
    {
        $this->salasApiClient = $salasApiClient;
        $this->reservationMapper = $reservationMapper;
        $this->cachePrefix = 'salas_api:reservation_service:';
    }

    /**
     * Create reservations from a SchoolClass
     *
     * @param SchoolClass $schoolclass
     * @return array Array of created reservations
     * @throws Exception When reservation creation fails
     */
    public function createReservationsFromSchoolClass(SchoolClass $schoolclass): array
    {
        $this->log('info', 'Iniciando criação de reservas para turma', [
            'operation' => 'create',
            'schoolclass_id' => $schoolclass->id,
            'disciplina' => $schoolclass->coddis,
            'turma' => $schoolclass->codtur,
            'sala_nome' => $schoolclass->room ? $schoolclass->room->nome : null,
        ]);

        try {
            // Validar que a turma tem sala alocada
            $this->validateSchoolClass($schoolclass);

            // Mapear SchoolClass para payload da API Salas
            $payload = $this->reservationMapper->mapSchoolClassToReservationPayload($schoolclass);

            $this->log('debug', 'Payload gerado para API Salas', [
                'operation' => 'create',
                'schoolclass_id' => $schoolclass->id,
                'payload' => $payload,
            ]);

            // Criar reserva via API Salas
            $response = $this->salasApiClient->post('/api/v1/reservas', $payload);

            // Processar resposta
            $reservationsData = $this->processCreateResponse($response);

            $this->log('info', 'Reservas criadas com sucesso via API Salas', [
                'operation' => 'create',
                'schoolclass_id' => $schoolclass->id,
                'disciplina' => $schoolclass->coddis,
                'sala_nome' => $schoolclass->room->nome,
                'reservas_criadas' => count($reservationsData),
                'reserva_principal_id' => $reservationsData[0]['id'] ?? null,
                'recorrente' => $response['data']['recurrent'] ?? false,
                'status' => 'success'
            ]);

            return $reservationsData;

        } catch (Exception $e) {
            $this->handleApiError($e, 'create', [
                'schoolclass_id' => $schoolclass->id,
                'disciplina' => $schoolclass->coddis,
                'sala_nome' => $schoolclass->room ? $schoolclass->room->nome : null,
            ]);
            throw $e;
        }
    }

    /**
     * Check availability for a SchoolClass
     *
     * @param SchoolClass $schoolclass
     * @return bool True if available, false if conflicts exist
     * @throws Exception When availability check fails
     */
    public function checkAvailabilityForSchoolClass(SchoolClass $schoolclass): bool
    {
        $this->log('info', 'Verificando disponibilidade de sala para turma', [
            'operation' => 'check_availability',
            'schoolclass_id' => $schoolclass->id,
            'disciplina' => $schoolclass->coddis,
            'sala_nome' => $schoolclass->room ? $schoolclass->room->nome : null,
        ]);

        try {
            // Validar que a turma tem sala alocada
            $this->validateSchoolClass($schoolclass);

            $salaId = $this->reservationMapper->getSalaIdFromNome($schoolclass->room->nome);

            // Cache para otimização de consultas repetidas
            $cacheKey = $this->cachePrefix . 'availability:' . $salaId . ':' . $schoolclass->id;
            $cacheTtl = 15 * 60; // 15 minutos

            $isAvailable = Cache::remember($cacheKey, $cacheTtl, function () use ($schoolclass, $salaId) {
                return $this->performAvailabilityCheck($schoolclass, $salaId);
            });

            $this->log('info', 'Verificação de disponibilidade concluída', [
                'operation' => 'check_availability',
                'schoolclass_id' => $schoolclass->id,
                'sala_id' => $salaId,
                'disponivel' => $isAvailable,
                'status' => 'success'
            ]);

            return $isAvailable;

        } catch (Exception $e) {
            $this->handleApiError($e, 'check_availability', [
                'schoolclass_id' => $schoolclass->id,
                'disciplina' => $schoolclass->coddis,
                'sala_nome' => $schoolclass->room ? $schoolclass->room->nome : null,
            ]);
            throw $e;
        }
    }

    /**
     * Cancel reservations for a Requisition
     *
     * @param Requisition $requisition
     * @return bool True if cancellation successful
     * @throws Exception When cancellation fails
     */
    public function cancelReservationsForRequisition(Requisition $requisition): bool
    {
        $this->log('info', 'Iniciando cancelamento de reservas para requisição', [
            'operation' => 'cancel',
            'requisition_id' => $requisition->id,
            'titulo' => $requisition->titulo,
        ]);

        try {
            // Buscar reservas relacionadas à requisição
            $reservationsToCancel = $this->findReservationsByRequisition($requisition);

            if (empty($reservationsToCancel)) {
                $this->log('warning', 'Nenhuma reserva encontrada para cancelamento', [
                    'operation' => 'cancel',
                    'requisition_id' => $requisition->id,
                    'titulo' => $requisition->titulo,
                ]);
                return true; // Não há nada para cancelar
            }

            $cancelledCount = 0;
            $errors = [];

            foreach ($reservationsToCancel as $reservation) {
                try {
                    $this->cancelSingleReservation($reservation);
                    $cancelledCount++;
                } catch (Exception $e) {
                    $errors[] = [
                        'reservation_id' => $reservation['id'],
                        'error' => $e->getMessage()
                    ];
                }
            }

            $success = empty($errors);

            $this->log($success ? 'info' : 'warning', 'Cancelamento de reservas concluído', [
                'operation' => 'cancel',
                'requisition_id' => $requisition->id,
                'reservas_encontradas' => count($reservationsToCancel),
                'reservas_canceladas' => $cancelledCount,
                'erros' => $errors,
                'status' => $success ? 'success' : 'partial_failure'
            ]);

            return $success;

        } catch (Exception $e) {
            $this->handleApiError($e, 'cancel', [
                'requisition_id' => $requisition->id,
                'titulo' => $requisition->titulo,
            ]);
            throw $e;
        }
    }

    /**
     * Validate that SchoolClass has required data for API operations
     *
     * @param SchoolClass $schoolclass
     * @throws Exception If validation fails
     */
    private function validateSchoolClass(SchoolClass $schoolclass): void
    {
        if (!$schoolclass->room) {
            throw new Exception('SchoolClass deve ter uma sala alocada para operações via API Salas');
        }

        if ($schoolclass->classschedules->isEmpty()) {
            throw new Exception('SchoolClass deve ter pelo menos um horário de aula definido');
        }
    }

    /**
     * Process response from create reservation API call
     *
     * @param array $response
     * @return array Array of reservation data
     */
    private function processCreateResponse(array $response): array
    {
        $reservations = [];

        if (isset($response['data'])) {
            $mainReservation = $response['data'];
            $reservations[] = $mainReservation;

            // Se é recorrente, a API retorna informações sobre todas as instâncias criadas
            if ($mainReservation['recurrent'] ?? false) {
                $instancesCreated = $mainReservation['instances_created'] ?? 1;
                
                // Para reservas recorrentes, consideramos que todas as instâncias foram criadas
                // A API não retorna detalhes de cada instância, apenas o total
                for ($i = 1; $i < $instancesCreated; $i++) {
                    $reservations[] = [
                        'id' => $mainReservation['id'] + $i, // ID estimado
                        'parent_id' => $mainReservation['id'],
                        'nome' => $mainReservation['nome'],
                        'recurrent' => true,
                        'instance_number' => $i + 1
                    ];
                }
            }
        }

        return $reservations;
    }

    /**
     * Perform the actual availability check against the API
     *
     * @param SchoolClass $schoolclass
     * @param int $salaId
     * @return bool
     */
    private function performAvailabilityCheck(SchoolClass $schoolclass, int $salaId): bool
    {
        // Verificar disponibilidade para cada dia da semana da turma
        foreach ($schoolclass->classschedules as $schedule) {
            $conflicts = $this->checkTimeConflicts($salaId, $schedule, $schoolclass);
            
            if (!empty($conflicts)) {
                $this->log('debug', 'Conflitos encontrados na verificação de disponibilidade', [
                    'operation' => 'check_availability',
                    'schoolclass_id' => $schoolclass->id,
                    'sala_id' => $salaId,
                    'dia_semana' => $schedule->diasmnocp,
                    'horario_inicio' => $schedule->horent,
                    'horario_fim' => $schedule->horsai,
                    'conflitos' => $conflicts
                ]);
                return false;
            }
        }

        return true;
    }

    /**
     * Check for time conflicts for a specific schedule
     *
     * @param int $salaId
     * @param object $schedule
     * @param SchoolClass $schoolclass
     * @return array Array of conflicting reservations
     */
    private function checkTimeConflicts(int $salaId, object $schedule, SchoolClass $schoolclass): array
    {
        try {
            // Mapear dia da semana para o formato da API (exemplo para hoje + próximos dias)
            $checkDates = $this->getCheckDates($schedule->diasmnocp, $schoolclass);
            $conflicts = [];

            foreach ($checkDates as $date) {
                $params = [
                    'sala' => $salaId,
                    'data' => $date
                ];

                $response = $this->salasApiClient->get('/api/v1/reservas', $params);
                $reservations = $response['data'] ?? [];

                foreach ($reservations as $reservation) {
                    if ($this->hasTimeOverlap($schedule, $reservation)) {
                        $conflicts[] = [
                            'id' => $reservation['id'],
                            'nome' => $reservation['nome'],
                            'data' => $reservation['data'],
                            'horario_inicio' => $reservation['horario_inicio'],
                            'horario_fim' => $reservation['horario_fim']
                        ];
                    }
                }
            }

            return $conflicts;
        } catch (Exception $e) {
            // Em caso de erro na API, assumir que há conflito por segurança
            $this->log('warning', 'Erro ao verificar conflitos, assumindo indisponibilidade por segurança', [
                'operation' => 'check_availability',
                'error' => $e->getMessage()
            ]);
            return [['error' => $e->getMessage()]];
        }
    }

    /**
     * Check if schedule overlaps with existing reservation
     *
     * @param object $schedule
     * @param array $reservation
     * @return bool
     */
    private function hasTimeOverlap(object $schedule, array $reservation): bool
    {
        $scheduleStart = $schedule->horent;
        $scheduleEnd = $schedule->horsai;
        $reservationStart = $reservation['horario_inicio'];
        $reservationEnd = $reservation['horario_fim'];

        // Ajustar horário de fim se necessário (lógica do sistema legado)
        if (explode(":", $scheduleEnd)[1] == "00") {
            $scheduleEnd = Carbon::createFromFormat('H:i:s', $scheduleEnd)->subMinute()->format('H:i');
        } else {
            $scheduleEnd = Carbon::createFromFormat('H:i:s', $scheduleEnd)->format('H:i');
        }
        $scheduleStart = Carbon::createFromFormat('H:i:s', $scheduleStart)->format('H:i');

        // Verificar sobreposição de horários
        return !($scheduleEnd <= $reservationStart || $scheduleStart >= $reservationEnd);
    }

    /**
     * Get dates to check based on day of week and school class period
     *
     * @param string $dayOfWeek
     * @param SchoolClass $schoolclass
     * @return array
     */
    private function getCheckDates(string $dayOfWeek, SchoolClass $schoolclass): array
    {
        // Mapear dias da semana
        $dayMapping = [
            'dom' => 0, 'seg' => 1, 'ter' => 2, 'qua' => 3,
            'qui' => 4, 'sex' => 5, 'sab' => 6
        ];

        $targetDay = $dayMapping[$dayOfWeek] ?? 1;
        $dates = [];

        // Gerar algumas datas de exemplo para verificação (próximos 4 semanas)
        $startDate = Carbon::now();
        $endDate = Carbon::createFromFormat('d/m/Y', $schoolclass->schoolterm->dtamaxres);

        for ($week = 0; $week < 4; $week++) {
            $date = $startDate->copy()->addWeeks($week);
            
            // Ajustar para o dia da semana correto
            while ($date->dayOfWeek !== $targetDay) {
                $date->addDay();
            }

            if ($date->lte($endDate)) {
                $dates[] = $date->format('Y-m-d');
            }
        }

        return $dates;
    }

    /**
     * Find reservations by requisition data
     *
     * @param Requisition $requisition
     * @return array
     */
    private function findReservationsByRequisition(Requisition $requisition): array
    {
        // Buscar reservas com o mesmo título da requisição
        $searchParams = [
            'nome' => $requisition->titulo,
        ];

        try {
            $response = $this->salasApiClient->get('/api/v1/reservas', $searchParams);
            return $response['data'] ?? [];
        } catch (Exception $e) {
            $this->log('warning', 'Erro ao buscar reservas para cancelamento', [
                'operation' => 'cancel',
                'requisition_id' => $requisition->id,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Cancel a single reservation
     *
     * @param array $reservation
     * @throws Exception
     */
    private function cancelSingleReservation(array $reservation): void
    {
        $endpoint = '/api/v1/reservas/' . $reservation['id'];

        // Se é recorrente, cancelar toda a série
        if ($reservation['recurrent'] ?? false) {
            $endpoint .= '?purge=true';
        }

        $this->salasApiClient->delete($endpoint);

        $this->log('debug', 'Reserva cancelada individualmente', [
            'operation' => 'cancel',
            'reservation_id' => $reservation['id'],
            'reservation_name' => $reservation['nome'] ?? 'N/A',
            'recorrente' => $reservation['recurrent'] ?? false
        ]);
    }

    /**
     * Handle API errors with structured logging
     *
     * @param Exception $exception
     * @param string $operation
     * @param array $context
     */
    private function handleApiError(Exception $exception, string $operation, array $context = []): void
    {
        $errorContext = array_merge($context, [
            'operation' => $operation,
            'error_message' => $exception->getMessage(),
            'error_class' => get_class($exception),
            'status' => 'error'
        ]);

        // Log específico baseado no tipo de erro
        if (str_contains($exception->getMessage(), 'Authentication')) {
            $this->log('error', 'Falha de autenticação na API Salas', $errorContext);
        } elseif (str_contains($exception->getMessage(), 'Rate limit')) {
            $this->log('warning', 'Rate limit atingido na API Salas', $errorContext);
        } elseif (str_contains($exception->getMessage(), 'Validation')) {
            $this->log('warning', 'Erro de validação na API Salas', $errorContext);
        } elseif (str_contains($exception->getMessage(), 'Connection')) {
            $this->log('error', 'Falha de conectividade com API Salas', $errorContext);
        } else {
            $this->log('error', 'Erro geral na operação com API Salas', $errorContext);
        }
    }

    /**
     * Log structured messages
     *
     * @param string $level
     * @param string $message
     * @param array $context
     */
    private function log(string $level, string $message, array $context = []): void
    {
        $context['component'] = 'ReservationApiService';
        $context['timestamp'] = now()->toISOString();
        
        Log::$level($message, $context);
    }
}