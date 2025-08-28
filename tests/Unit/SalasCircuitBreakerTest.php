<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\SalasCircuitBreaker;
use Illuminate\Support\Facades\Cache;
use Exception;

class SalasCircuitBreakerTest extends TestCase
{
    private SalasCircuitBreaker $circuitBreaker;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Clear any existing cache
        Cache::flush();
        
        $this->circuitBreaker = new SalasCircuitBreaker();
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    /** @test */
    public function circuit_breaker_starts_in_closed_state()
    {
        $this->assertEquals('closed', $this->circuitBreaker->getCurrentState());
        $this->assertTrue($this->circuitBreaker->canExecute());
        $this->assertEquals(0, $this->circuitBreaker->getFailureCount());
    }

    /** @test */
    public function circuit_breaker_records_failures()
    {
        $exception = new Exception('Test failure');
        
        $this->circuitBreaker->recordFailure($exception);
        $this->assertEquals(1, $this->circuitBreaker->getFailureCount());
        $this->assertEquals('closed', $this->circuitBreaker->getCurrentState());
    }

    /** @test */
    public function circuit_breaker_opens_after_threshold_failures()
    {
        $exception = new Exception('Test failure');
        
        // Record failures up to threshold
        for ($i = 0; $i < 5; $i++) {
            $this->circuitBreaker->recordFailure($exception);
        }
        
        $this->assertEquals(5, $this->circuitBreaker->getFailureCount());
        $this->assertEquals('open', $this->circuitBreaker->getCurrentState());
        
        // Should throw exception when trying to execute
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Circuit breaker is OPEN');
        $this->circuitBreaker->canExecute();
    }

    /** @test */
    public function circuit_breaker_resets_on_success()
    {
        $exception = new Exception('Test failure');
        
        // Record some failures
        $this->circuitBreaker->recordFailure($exception);
        $this->circuitBreaker->recordFailure($exception);
        $this->assertEquals(2, $this->circuitBreaker->getFailureCount());
        
        // Record success
        $this->circuitBreaker->recordSuccess();
        $this->assertEquals(0, $this->circuitBreaker->getFailureCount());
    }

    /** @test */
    public function circuit_breaker_can_be_force_reset()
    {
        $exception = new Exception('Test failure');
        
        // Open the circuit
        for ($i = 0; $i < 5; $i++) {
            $this->circuitBreaker->recordFailure($exception);
        }
        
        $this->assertEquals('open', $this->circuitBreaker->getCurrentState());
        
        // Force reset
        $this->circuitBreaker->forceReset();
        
        $this->assertEquals('closed', $this->circuitBreaker->getCurrentState());
        $this->assertEquals(0, $this->circuitBreaker->getFailureCount());
        $this->assertTrue($this->circuitBreaker->canExecute());
    }

    /** @test */
    public function circuit_breaker_metrics_are_accurate()
    {
        $metrics = $this->circuitBreaker->getMetrics();
        
        $this->assertIsArray($metrics);
        $this->assertArrayHasKey('enabled', $metrics);
        $this->assertArrayHasKey('state', $metrics);
        $this->assertArrayHasKey('failure_count', $metrics);
        $this->assertArrayHasKey('failure_threshold', $metrics);
        $this->assertArrayHasKey('can_execute', $metrics);
        
        $this->assertTrue($metrics['enabled']);
        $this->assertEquals('closed', $metrics['state']);
        $this->assertEquals(0, $metrics['failure_count']);
        $this->assertEquals(5, $metrics['failure_threshold']);
        $this->assertTrue($metrics['can_execute']);
    }

    /** @test */
    public function circuit_breaker_respects_disabled_configuration()
    {
        // Create a new circuit breaker with disabled configuration
        config(['salas.circuit_breaker.enabled' => false]);
        
        $disabledCircuitBreaker = new SalasCircuitBreaker();
        
        $this->assertFalse($disabledCircuitBreaker->isEnabled());
        $this->assertTrue($disabledCircuitBreaker->canExecute());
        
        // Even with many failures, should still allow execution
        $exception = new Exception('Test failure');
        for ($i = 0; $i < 10; $i++) {
            $disabledCircuitBreaker->recordFailure($exception);
        }
        
        $this->assertTrue($disabledCircuitBreaker->canExecute());
    }
}