<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AllocationProgressWebhookController extends Controller
{
    /**
     * Handle progress webhook from the Python solver.
     */
    public function __invoke(Request $request)
    {
        // if (! $this->tokenIsValid($request)) {
        //     abort(401, 'Unauthorized');
        // }

        $validated = $request->validate([
            'job_id' => 'required|string',
            'progress' => 'required|numeric|min:0|max:100',
            'message' => 'nullable|string',
        ]);

        $jobId = $validated['job_id'];
        $progress = $validated['progress'];
        $message = $validated['message'] ?? '';

        // Find the active allocation entry by job_id
        $schoolTermId = $this->findSchoolTermIdByJobId($jobId);

        if ($schoolTermId === null) {
            Log::warning('AllocationProgressWebhook: received progress for unknown job_id', [
                'job_id' => $jobId,
            ]);

            return response()->json(['message' => 'Job not found'], 404);
        }

        $cacheKey = "allocation:{$schoolTermId}";
        $existing = Cache::get($cacheKey);

        if ($existing === null) {
            return response()->json(['message' => 'Job not found'], 404);
        }

        // Idempotency / zombie guard: ignore progress for obsolete jobs
        if (($existing['job_id'] ?? null) !== $jobId) {
            Log::warning('AllocationProgressWebhook: ignoring obsolete progress', [
                'job_id' => $jobId,
                'school_term_id' => $schoolTermId,
            ]);

            return response()->json(['message' => 'Ignored. Obsolete job.'], 200);
        }

        // Ignore out-of-order progress updates and terminal statuses
        $terminalStatuses = ['completed', 'error'];
        $isTerminal = in_array($existing['status'] ?? '', $terminalStatuses, true);
        $currentProgress = $existing['progress'] ?? 0;

        if ($isTerminal || $progress < $currentProgress) {
            Log::info('AllocationProgressWebhook: ignoring stale progress', [
                'job_id' => $jobId,
                'school_term_id' => $schoolTermId,
                'received_progress' => $progress,
                'current_progress' => $currentProgress,
                'current_status' => $existing['status'] ?? 'unknown',
            ]);

            return response()->json(['message' => 'Ignored. Stale progress.'], 200);
        }

        $existing['progress'] = $progress;
        $existing['message'] = $message;
        $existing['status'] = $progress >= 100 ? 'completed' : 'solving';
        $existing['updated_at'] = now()->toIso8601String();

        Cache::put($cacheKey, $existing, now()->addHours(4));

        Log::info('AllocationProgressWebhook: progress updated', [
            'job_id' => $jobId,
            'school_term_id' => $schoolTermId,
            'progress' => $progress,
        ]);

        return response()->json(['message' => 'Progress updated'], 200);
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

        // Fallback: brute-force search over likely school term IDs
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
