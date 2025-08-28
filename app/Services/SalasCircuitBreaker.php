<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Exception;

/**
 * Circuit Breaker implementation for Salas API
 * 
 * Implements the circuit breaker pattern to handle API failures gracefully:
 * - CLOSED: Normal operation, requests pass through
 * - OPEN: API is failing, requests are blocked
 * - HALF_OPEN: Testing if API has recovered
 */
class SalasCircuitBreaker
{
    // Circuit states
    public const STATE_CLOSED = 'closed';
    public const STATE_OPEN = 'open';
    public const STATE_HALF_OPEN = 'half_open';

    private array $config;
    private string $cachePrefix;

    public function __construct()
    {
        $this->config = config('salas.circuit_breaker', []);
        $this->cachePrefix = 'salas_api:circuit_breaker:';
        
        // Set default values if not configured
        $this->config = array_merge([
            'enabled' => true,
            'failure_threshold' => 5,
            'timeout_duration' => 300, // 5 minutes
            'recovery_timeout' => 30,  // 30 seconds
        ], $this->config);
    }

    /**
     * Check if circuit breaker allows the request to proceed
     *
     * @return bool True if request can proceed, false if blocked
     * @throws Exception If circuit is open
     */
    public function canExecute(): bool
    {
        if (!$this->config['enabled']) {
            return true;
        }

        $state = $this->getCurrentState();
        
        switch ($state) {
            case self::STATE_CLOSED:
                return true;
                
            case self::STATE_OPEN:
                if ($this->shouldAttemptRecovery()) {
                    $this->transitionTo(self::STATE_HALF_OPEN);
                    $this->log('info', 'Circuit breaker transitioning to HALF_OPEN for recovery test');
                    return true;
                }
                
                $this->log('warning', 'Circuit breaker is OPEN - blocking request');
                throw new Exception('Circuit breaker is OPEN - API calls are blocked due to repeated failures');
                
            case self::STATE_HALF_OPEN:
                return true;
                
            default:
                return true;
        }
    }

    /**
     * Record a successful API call
     */
    public function recordSuccess(): void
    {
        if (!$this->config['enabled']) {
            return;
        }

        $state = $this->getCurrentState();
        
        if ($state === self::STATE_HALF_OPEN) {
            $this->transitionTo(self::STATE_CLOSED);
            $this->log('info', 'Circuit breaker recovered - transitioning to CLOSED');
        }
        
        // Reset failure count on any success
        $this->resetFailureCount();
    }

    /**
     * Record a failed API call
     *
     * @param Exception $exception The exception that caused the failure
     */
    public function recordFailure(Exception $exception): void
    {
        if (!$this->config['enabled']) {
            return;
        }

        $failureCount = $this->incrementFailureCount();
        $state = $this->getCurrentState();
        
        $this->log('warning', 'Circuit breaker recorded API failure', [
            'failure_count' => $failureCount,
            'threshold' => $this->config['failure_threshold'],
            'current_state' => $state,
            'error' => $exception->getMessage(),
            'error_class' => get_class($exception)
        ]);

        // Check if we should open the circuit
        if ($failureCount >= $this->config['failure_threshold']) {
            if ($state !== self::STATE_OPEN) {
                $this->transitionTo(self::STATE_OPEN);
                $this->log('error', 'Circuit breaker OPENED due to consecutive failures', [
                    'failure_count' => $failureCount,
                    'threshold' => $this->config['failure_threshold'],
                    'timeout_duration' => $this->config['timeout_duration']
                ]);
            }
        }
    }

    /**
     * Get current circuit breaker state
     *
     * @return string Current state (closed, open, half_open)
     */
    public function getCurrentState(): string
    {
        if (!$this->config['enabled']) {
            return self::STATE_CLOSED;
        }

        $stateKey = $this->cachePrefix . 'state';
        return Cache::get($stateKey, self::STATE_CLOSED);
    }

    /**
     * Get failure count
     *
     * @return int Current failure count
     */
    public function getFailureCount(): int
    {
        if (!$this->config['enabled']) {
            return 0;
        }

        $countKey = $this->cachePrefix . 'failure_count';
        return (int) Cache::get($countKey, 0);
    }

    /**
     * Get circuit breaker metrics for monitoring
     *
     * @return array Circuit breaker metrics
     */
    public function getMetrics(): array
    {
        $state = $this->getCurrentState();
        $failureCount = $this->getFailureCount();
        $lastFailure = $this->getLastFailureTime();
        
        return [
            'enabled' => $this->config['enabled'],
            'state' => $state,
            'failure_count' => $failureCount,
            'failure_threshold' => $this->config['failure_threshold'],
            'last_failure_time' => $lastFailure,
            'timeout_duration' => $this->config['timeout_duration'],
            'can_execute' => $state !== self::STATE_OPEN || $this->shouldAttemptRecovery(),
            'next_retry_time' => $this->getNextRetryTime(),
        ];
    }

    /**
     * Force reset the circuit breaker (for admin/emergency use)
     */
    public function forceReset(): void
    {
        $this->transitionTo(self::STATE_CLOSED);
        $this->resetFailureCount();
        $this->clearLastFailureTime();
        
        $this->log('warning', 'Circuit breaker force reset by admin');
    }

    /**
     * Check if circuit breaker is enabled in configuration
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->config['enabled'];
    }

    /**
     * Transition to a new state
     *
     * @param string $newState
     */
    private function transitionTo(string $newState): void
    {
        $currentState = $this->getCurrentState();
        
        if ($currentState === $newState) {
            return;
        }

        $stateKey = $this->cachePrefix . 'state';
        $ttl = $this->getStateTtl($newState);
        
        Cache::put($stateKey, $newState, $ttl);
        
        // Record state transition time
        $transitionKey = $this->cachePrefix . 'state_transition_time';
        Cache::put($transitionKey, now()->timestamp, $ttl);
    }

    /**
     * Get TTL for state cache based on the state
     *
     * @param string $state
     * @return int TTL in seconds
     */
    private function getStateTtl(string $state): int
    {
        switch ($state) {
            case self::STATE_OPEN:
                return $this->config['timeout_duration'] + 60; // Extra buffer
                
            case self::STATE_HALF_OPEN:
                return $this->config['recovery_timeout'] + 30; // Extra buffer
                
            default:
                return 3600; // 1 hour for closed state
        }
    }

    /**
     * Increment failure count
     *
     * @return int New failure count
     */
    private function incrementFailureCount(): int
    {
        $countKey = $this->cachePrefix . 'failure_count';
        $count = (int) Cache::get($countKey, 0) + 1;
        
        // Store with longer TTL to survive state transitions
        Cache::put($countKey, $count, $this->config['timeout_duration'] + 600);
        
        // Record time of this failure
        $timeKey = $this->cachePrefix . 'last_failure_time';
        Cache::put($timeKey, now()->timestamp, $this->config['timeout_duration'] + 600);
        
        return $count;
    }

    /**
     * Reset failure count
     */
    private function resetFailureCount(): void
    {
        $countKey = $this->cachePrefix . 'failure_count';
        Cache::forget($countKey);
        
        $this->clearLastFailureTime();
    }

    /**
     * Clear last failure time
     */
    private function clearLastFailureTime(): void
    {
        $timeKey = $this->cachePrefix . 'last_failure_time';
        Cache::forget($timeKey);
    }

    /**
     * Get last failure time
     *
     * @return int|null Timestamp of last failure, null if none
     */
    private function getLastFailureTime(): ?int
    {
        $timeKey = $this->cachePrefix . 'last_failure_time';
        return Cache::get($timeKey);
    }

    /**
     * Check if we should attempt recovery (transition from OPEN to HALF_OPEN)
     *
     * @return bool
     */
    private function shouldAttemptRecovery(): bool
    {
        $transitionKey = $this->cachePrefix . 'state_transition_time';
        $transitionTime = Cache::get($transitionKey);
        
        if (!$transitionTime) {
            return true; // No transition time recorded, allow recovery
        }
        
        $elapsedTime = now()->timestamp - $transitionTime;
        return $elapsedTime >= $this->config['timeout_duration'];
    }

    /**
     * Get next retry time for when circuit will allow requests again
     *
     * @return int|null Timestamp when next retry is allowed, null if not applicable
     */
    private function getNextRetryTime(): ?int
    {
        $state = $this->getCurrentState();
        
        if ($state !== self::STATE_OPEN) {
            return null;
        }
        
        $transitionKey = $this->cachePrefix . 'state_transition_time';
        $transitionTime = Cache::get($transitionKey);
        
        if (!$transitionTime) {
            return null;
        }
        
        return $transitionTime + $this->config['timeout_duration'];
    }

    /**
     * Log circuit breaker events
     *
     * @param string $level
     * @param string $message
     * @param array $context
     */
    private function log(string $level, string $message, array $context = []): void
    {
        $context['component'] = 'SalasCircuitBreaker';
        $context['timestamp'] = now()->toISOString();
        $context['config'] = [
            'enabled' => $this->config['enabled'],
            'failure_threshold' => $this->config['failure_threshold'],
            'timeout_duration' => $this->config['timeout_duration']
        ];
        
        Log::$level($message, $context);
    }
}