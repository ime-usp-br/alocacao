<?php

namespace App\Http\Controllers;

use App\Models\SchoolClass;
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

        // Store suggestions (Pass 2) for potential manual review
        if (! empty($suggestions)) {
            Cache::put(
                "allocation_suggestions:{$schoolTermId}",
                $suggestions,
                now()->addDays(7)
            );
        }

        $activeJob['status'] = 'completed';
        $activeJob['progress'] = 100;
        $activeJob['message'] = 'Distribuição concluída';
        $activeJob['finished_at'] = now()->toIso8601String();
        $activeJob['assignments_count'] = $autoCount;
        $activeJob['unassigned_count'] = count($unassignedGroups) - $manualCount;
        $activeJob['manual_count'] = $manualCount;
        Cache::put($cacheKey, $activeJob, now()->addHours(4));

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
