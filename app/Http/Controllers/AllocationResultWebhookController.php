<?php

namespace App\Http\Controllers;

use App\Models\AllocationState;
use App\Models\ClassSchedule;
use App\Models\Room;
use App\Models\SchoolClass;
use App\Models\SchoolTerm;
use App\Models\SolverLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AllocationResultWebhookController extends Controller
{
    /**
     * Handle the final result webhook from the Python solver.
     */
    public function __invoke(Request $request)
    {
        // if (! $this->tokenIsValid($request)) {
        //     abort(401, 'Unauthorized');
        // }

        $validated = $request->validate([
            'job_id' => 'required|string',
            'status' => 'required|string|in:optimal,feasible,stopped,infeasible,error',
            'allocations' => 'nullable|array',
            'allocations.*.group_id' => 'required_with:allocations|integer',
            'allocations.*.room_id' => 'required_with:allocations|integer',
            'unassigned_groups' => 'nullable|array',
            'unassigned_groups.*' => 'integer',
            'suggestions' => 'nullable|array',
            'suggestions.*.group_id' => 'required_with:suggestions|integer',
            'suggestions.*.timeslot_id' => 'required_with:suggestions|integer',
            'suggestions.*.suggested_room_id' => 'required_with:suggestions|integer',
            'metrics' => 'nullable|array',
        ]);

        $jobId = $validated['job_id'];
        $status = $validated['status'];
        $assignments = $validated['allocations'] ?? [];
        $unassignedGroups = $validated['unassigned_groups'] ?? [];
        $suggestions = $validated['suggestions'] ?? [];

        $schoolTermId = $this->findSchoolTermIdByJobId($jobId);

        if ($schoolTermId === null) {
            Log::warning('AllocationResultWebhook: received result for unknown job_id', [
                'job_id' => $jobId,
            ]);

            return response()->json(['message' => 'Job not found'], 404);
        }

        $cacheKey = "allocation:{$schoolTermId}";
        $activeJob = Cache::get($cacheKey);

        // Idempotency / zombie guard
        if (! $activeJob || ($activeJob['job_id'] ?? null) !== $jobId) {
            Log::warning('AllocationResultWebhook: ignoring obsolete job result', [
                'job_id' => $jobId,
                'school_term_id' => $schoolTermId,
            ]);

            return response()->json(['message' => 'Ignored. Obsolete job.'], 200);
        }

        if ($status === 'error') {
            $activeJob['status'] = 'error';
            $activeJob['progress'] = 100;
            $activeJob['message'] = 'Erro no solver';
            $activeJob['finished_at'] = now()->toIso8601String();
            Cache::put($cacheKey, $activeJob, now()->addHours(4));

            SolverLog::where('job_id', $jobId)->update([
                'response' => $validated,
                'status' => $status,
                'responded_at' => now(),
            ]);

            Log::error('AllocationResultWebhook: solver reported error', [
                'job_id' => $jobId,
                'school_term_id' => $schoolTermId,
            ]);

            return response()->json(['message' => 'Error recorded'], 200);
        }

        $manualCount = 0;
        $autoCount = 0;

        try {
            DB::transaction(function () use ($assignments, $unassignedGroups, &$manualCount, &$autoCount) {
                // Apply allocated room_ids
                foreach ($assignments as $assignment) {
                    $groupId = $assignment['group_id'];
                    $roomId = $assignment['room_id'];

                    SchoolClass::where('id', $groupId)->update(['room_id' => $roomId]);
                    $autoCount++;
                }

                // Clear unassigned groups, but preserve manual allocations
                if (! empty($unassignedGroups)) {
                    $alreadyAllocated = SchoolClass::whereIn('id', $unassignedGroups)
                        ->whereNotNull('room_id')
                        ->pluck('id')
                        ->toArray();

                    $toClear = array_diff($unassignedGroups, $alreadyAllocated);
                    if (! empty($toClear)) {
                        SchoolClass::whereIn('id', $toClear)->update(['room_id' => null]);
                    }

                    $manualCount = count($alreadyAllocated);
                }
            });
        } catch (\Exception $e) {
            Log::error('AllocationResultWebhook: failed to apply results atomically', [
                'job_id' => $jobId,
                'school_term_id' => $schoolTermId,
                'error' => $e->getMessage(),
            ]);

            $activeJob['status'] = 'error';
            $activeJob['progress'] = 100;
            $activeJob['message'] = 'Falha ao aplicar resultados no banco';
            $activeJob['finished_at'] = now()->toIso8601String();
            Cache::put($cacheKey, $activeJob, now()->addHours(4));

            return response()->json(['message' => 'Failed to apply results'], 500);
        }

        // Store suggestions (real split-class hints) for manual review
        if (! empty($suggestions)) {
            Cache::put(
                "allocation_suggestions:{$schoolTermId}",
                [
                    'raw' => $suggestions,
                    'formatted' => $this->formatSuggestionsForDisplay($suggestions, $jobId),
                ],
                now()->addDays(7)
            );
        }

        $comparisonMessage = $this->buildComparisonMessage($jobId, $schoolTermId);

        $activeJob['status'] = 'completed';
        $activeJob['progress'] = 100;
        $activeJob['message'] = $comparisonMessage ?? 'Distribuição concluída';
        $activeJob['finished_at'] = now()->toIso8601String();
        $activeJob['assignments_count'] = $autoCount;
        $activeJob['unassigned_count'] = count($unassignedGroups) - $manualCount;
        $activeJob['manual_count'] = $manualCount;
        Cache::put($cacheKey, $activeJob, now()->addHours(4));

        SolverLog::where('job_id', $jobId)->update([
            'response' => $validated,
            'status' => $status,
            'allocations_count' => $autoCount,
            'unassigned_count' => count($unassignedGroups) - $manualCount,
            'manual_count' => $manualCount,
            'responded_at' => now(),
        ]);

        Log::info('AllocationResultWebhook: results applied successfully', [
            'job_id' => $jobId,
            'school_term_id' => $schoolTermId,
            'assignments_count' => $autoCount,
            'unassigned_count' => count($unassignedGroups) - $manualCount,
            'manual_count' => $manualCount,
        ]);

        return response()->json(['message' => 'Results applied'], 200);
    }

    /**
     * Build a rich comparison message between the pre-solver allocation state
     * and the current state after the solver result has been applied.
     */
    private function buildComparisonMessage(string $jobId, int $schoolTermId): ?string
    {
        $solverLog = SolverLog::where('job_id', $jobId)->first();

        if (! $solverLog) {
            return null;
        }

        $preState = AllocationState::where('solver_log_id', $solverLog->id)
            ->where('school_term_id', $schoolTermId)
            ->latest()
            ->first();

        if (! $preState) {
            return null;
        }

        $preAllocations = $preState->allocations ?? [];

        $currentClasses = SchoolClass::whereBelongsTo(SchoolTerm::find($schoolTermId))
            ->where('externa', false)
            ->get()
            ->keyBy('id');

        $newAllocations = 0;
        $changedRooms = 0;

        foreach ($currentClasses as $id => $schoolClass) {
            $currentRoomId = $schoolClass->room_id;
            $previousRoomId = $preAllocations[$id] ?? null;

            if ($previousRoomId === null && $currentRoomId !== null) {
                $newAllocations++;
            } elseif ($previousRoomId !== null && $currentRoomId !== null && (int) $previousRoomId !== (int) $currentRoomId) {
                $changedRooms++;
            }
        }

        if ($newAllocations === 0 && $changedRooms === 0) {
            return 'Distribuição concluída: nenhuma alteração em relação ao estado pré-solver.';
        }

        $parts = [];
        if ($newAllocations > 0) {
            $parts[] = "{$newAllocations} turma(s) nova(s) foi(ram) alocada(s)";
        }
        if ($changedRooms > 0) {
            $parts[] = "{$changedRooms} turma(s) mudou(aram) de sala";
        }

        return 'Distribuição concluída: ' . implode(', ', $parts) . '.';
    }

    /**
     * Enrich raw solver suggestions with human-readable day and room names,
     * grouped by school class for display in the UI.
     *
     * The solver returns timeslot_id as the index inside the payload timeslots
     * array. We map it back to the local class_schedules record using the
     * dispatched payload stored in SolverLog.
     */
    private function formatSuggestionsForDisplay(array $suggestions, string $jobId): array
    {
        if (empty($suggestions)) {
            return [];
        }

        $timeslotMap = $this->buildTimeslotToScheduleMap($suggestions, $jobId);

        $groupIds = array_values(array_unique(array_column($suggestions, 'group_id')));
        $roomIds = array_values(array_unique(array_column($suggestions, 'suggested_room_id')));

        $classes = SchoolClass::whereIn('id', $groupIds)
            ->get()
            ->keyBy('id');

        $rooms = Room::whereIn('id', $roomIds)
            ->get()
            ->keyBy('id');

        $scheduleIds = array_values(array_filter($timeslotMap));
        $schedules = ClassSchedule::whereIn('id', $scheduleIds)
            ->get()
            ->keyBy('id');

        $grouped = [];
        foreach ($suggestions as $suggestion) {
            $class = $classes[$suggestion['group_id']] ?? null;
            $room = $rooms[$suggestion['suggested_room_id']] ?? null;
            $scheduleId = $timeslotMap[$suggestion['timeslot_id']] ?? null;
            $schedule = $scheduleId ? ($schedules[$scheduleId] ?? null) : null;

            if (! $class || ! $room || ! $schedule) {
                continue;
            }

            $groupId = $class->id;
            if (! isset($grouped[$groupId])) {
                $classLabel = $class->tiptur === 'Graduação'
                    ? 'T.'.substr($class->codtur, -2)
                    : $class->codtur;
                $grouped[$groupId] = [
                    'label' => "Turma {$classLabel}",
                    'days' => [],
                ];
            }

            $day = ucfirst($schedule->diasmnocp);
            $grouped[$groupId]['days'][$day] = $room->nome;
        }

        $formatted = [];
        foreach ($grouped as $group) {
            $parts = [];
            foreach ($group['days'] as $day => $roomName) {
                $parts[] = "{$day}: {$roomName}";
            }
            $formatted[] = $group['label'] . ' - ' . implode(', ', $parts);
        }

        return $formatted;
    }

    /**
     * Map solver timeslot indices back to local class_schedule IDs using the
     * payload stored when the job was dispatched.
     */
    private function buildTimeslotToScheduleMap(array $suggestions, string $jobId): array
    {
        $timeslotIds = array_values(array_unique(array_column($suggestions, 'timeslot_id')));

        $solverLog = SolverLog::where('job_id', $jobId)->first();
        $payload = $solverLog ? ($solverLog->payload ?? []) : [];
        $timeslots = $payload['timeslots'] ?? [];

        $map = [];
        foreach ($timeslotIds as $timeslotId) {
            $timeslot = collect($timeslots)->firstWhere('id', $timeslotId);
            if (! $timeslot || empty($timeslot['label'])) {
                continue;
            }

            $schedule = $this->findScheduleByTimeslotLabel($timeslot['label']);
            if ($schedule) {
                $map[$timeslotId] = $schedule->id;
            }
        }

        return $map;
    }

    /**
     * Find a class schedule record that matches a payload timeslot label.
     */
    private function findScheduleByTimeslotLabel(string $label): ?ClassSchedule
    {
        $parts = explode('_', $label);
        if (count($parts) !== 3) {
            return null;
        }

        [$day, $startRaw, $endRaw] = $parts;
        $start = substr($startRaw, 0, 2) . ':' . substr($startRaw, 2, 2);
        $end = substr($endRaw, 0, 2) . ':' . substr($endRaw, 2, 2);

        return ClassSchedule::where('diasmnocp', $day)
            ->where('horent', $start)
            ->where('horsai', $end)
            ->first();
    }

    /**
     * Validate the webhook token.
     */
    private function tokenIsValid(Request $request): bool
    {
        $expectedToken = config('alocacao.solver.api_token');

        if (empty($expectedToken)) {
            return false;
        }

        return $request->header('X-Webhook-Token') === $expectedToken;
    }

    /**
     * Find the school term ID associated with the given job ID.
     */
    private function findSchoolTermIdByJobId(string $jobId): ?int
    {
        // Use secondary index first
        $schoolTermId = Cache::get("allocation:job:{$jobId}");

        if ($schoolTermId !== null) {
            return (int) $schoolTermId;
        }

        // Fallback: brute-force search
        $maxId = 1000;
        for ($id = 1; $id <= $maxId; $id++) {
            $cached = Cache::get("allocation:{$id}");
            if (isset($cached['job_id']) && $cached['job_id'] === $jobId) {
                return $id;
            }
        }

        return null;
    }
}
