<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Exception;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\ConnectionException;

class SalasApiClient
{
    private array $config;
    private ?string $authToken = null;
    private array $requestTimes = [];
    private string $cachePrefix;

    public function __construct()
    {
        $this->config = config('salas');
        $this->cachePrefix = $this->config['cache']['prefix'] ?? 'salas_api:';
        
        $this->validateConfiguration();
    }

    /**
     * Authenticate with the Salas API and return the token
     *
     * @return string Authentication token
     * @throws Exception When authentication fails
     */
    public function authenticate(): string
    {
        $cacheKey = $this->cachePrefix . 'auth_token';
        
        // Try to get cached token first
        if ($this->config['cache']['enabled'] && Cache::has($cacheKey)) {
            $this->authToken = Cache::get($cacheKey);
            return $this->authToken;
        }

        $credentials = $this->config['credentials'];
        
        if (empty($credentials['email']) || empty($credentials['password'])) {
            throw new Exception('Salas API credentials not configured. Please set SALAS_API_EMAIL and SALAS_API_PASSWORD environment variables.');
        }

        $payload = [
            'email' => $credentials['email'],
            'password' => $credentials['password'],
            'token_name' => 'Sistema de Alocação - ' . date('Y-m-d H:i:s')
        ];

        try {
            $response = $this->makeRequest('POST', '/api/v1/auth/token', $payload, false);
            
            if (!isset($response['data']['token'])) {
                throw new Exception('Invalid authentication response format');
            }

            $this->authToken = $response['data']['token'];
            
            // Cache the token
            if ($this->config['cache']['enabled']) {
                $ttl = $this->config['cache']['ttl']['auth_token'] ?? 3600;
                Cache::put($cacheKey, $this->authToken, $ttl);
            }

            $this->log('info', 'Successfully authenticated with Salas API', [
                'user' => $response['data']['user']['email'] ?? 'unknown'
            ]);

            return $this->authToken;

        } catch (Exception $e) {
            $this->log('error', 'Authentication failed', [
                'email' => $credentials['email'],
                'error' => $e->getMessage()
            ]);
            throw new Exception('Authentication with Salas API failed: ' . $e->getMessage());
        }
    }

    /**
     * Make a GET request to the API
     *
     * @param string $endpoint
     * @param array $params
     * @return array
     * @throws Exception
     */
    public function get(string $endpoint, array $params = []): array
    {
        $this->ensureAuthenticated();
        return $this->makeRequest('GET', $endpoint, $params);
    }

    /**
     * Make a POST request to the API
     *
     * @param string $endpoint
     * @param array $data
     * @return array
     * @throws Exception
     */
    public function post(string $endpoint, array $data): array
    {
        $this->ensureAuthenticated();
        return $this->makeRequest('POST', $endpoint, $data);
    }

    /**
     * Make a DELETE request to the API
     *
     * @param string $endpoint
     * @param array $data
     * @return array
     * @throws Exception
     */
    public function delete(string $endpoint, array $data = []): array
    {
        $this->ensureAuthenticated();
        return $this->makeRequest('DELETE', $endpoint, $data);
    }

    /**
     * Make an HTTP request with retry logic and rate limiting
     *
     * @param string $method
     * @param string $endpoint
     * @param array $data
     * @param bool $requiresAuth
     * @return array
     * @throws Exception
     */
    private function makeRequest(string $method, string $endpoint, array $data = [], bool $requiresAuth = true): array
    {
        return $this->retry(function() use ($method, $endpoint, $data, $requiresAuth) {
            $this->handleRateLimit();
            
            $url = rtrim($this->config['api_url'], '/') . $endpoint;
            $options = [
                'timeout' => $this->config['timeout']['request'] ?? 30,
                'connect_timeout' => $this->config['timeout']['connection'] ?? 10,
            ];

            // Add authentication header if required
            if ($requiresAuth && $this->authToken) {
                $options['headers']['Authorization'] = 'Bearer ' . $this->authToken;
            }

            $this->logRequest($method, $endpoint, $data);

            $request = Http::withOptions($options);

            try {
                switch(strtoupper($method)) {
                    case 'GET':
                        $response = $request->get($url, $data);
                        break;
                    case 'POST':
                        $response = $request->post($url, $data);
                        break;
                    case 'PATCH':
                        $response = $request->patch($url, $data);
                        break;
                    case 'DELETE':
                        $response = $request->delete($url, $data);
                        break;
                    default:
                        throw new Exception("Unsupported HTTP method: $method");
                }

                $this->logResponse($response->status(), $response->json());

                // Handle specific HTTP status codes
                if ($response->status() === 401) {
                    $this->clearAuthCache();
                    throw new Exception('Authentication failed - token may have expired');
                }

                if ($response->status() === 403) {
                    throw new Exception('Access denied - insufficient permissions');
                }

                if ($response->status() === 404) {
                    throw new Exception('Resource not found');
                }

                if ($response->status() === 422) {
                    $errorData = $response->json();
                    $message = $errorData['message'] ?? 'Validation failed';
                    if (isset($errorData['errors'])) {
                        $errors = collect($errorData['errors'])->flatten()->implode(', ');
                        $message .= ': ' . $errors;
                    }
                    throw new Exception($message);
                }

                if ($response->status() === 429) {
                    $retryAfter = $response->header('Retry-After') ?? 60;
                    throw new Exception("Rate limit exceeded. Retry after {$retryAfter} seconds");
                }

                if ($response->status() >= 500) {
                    throw new Exception('Server error: ' . $response->status());
                }

                if (!$response->successful()) {
                    throw new Exception("Request failed with status: " . $response->status());
                }

                return $response->json();

            } catch (ConnectionException $e) {
                throw new Exception('Connection failed: ' . $e->getMessage());
            } catch (RequestException $e) {
                if ($e->response->status() === 429) {
                    $retryAfter = $e->response->header('Retry-After') ?? 60;
                    sleep($retryAfter);
                    throw $e; // Let retry mechanism handle it
                }
                throw new Exception('Request failed: ' . $e->getMessage());
            }
        }, $this->config['retry']['max_attempts'] ?? 3);
    }

    /**
     * Ensure we have a valid authentication token
     *
     * @throws Exception
     */
    private function ensureAuthenticated(): void
    {
        if (!$this->authToken) {
            $this->authenticate();
        }
    }

    /**
     * Implement rate limiting
     *
     * @return void
     */
    private function handleRateLimit(): void
    {
        $now = microtime(true);
        $windowSize = 60; // 1 minute window
        $maxRequests = $this->config['rate_limiting']['requests_per_minute'] ?? 30;

        // Clean old requests outside the window
        $this->requestTimes = array_filter($this->requestTimes, function($time) use ($now, $windowSize) {
            return ($now - $time) < $windowSize;
        });

        // Check if we're at the limit
        if (count($this->requestTimes) >= $maxRequests) {
            $oldestRequest = min($this->requestTimes);
            $sleepTime = $windowSize - ($now - $oldestRequest) + 1;
            
            $this->log('warning', 'Rate limit reached, sleeping', [
                'sleep_seconds' => $sleepTime,
                'requests_in_window' => count($this->requestTimes)
            ]);

            sleep((int) ceil($sleepTime));
        }

        // Record this request
        $this->requestTimes[] = $now;
    }

    /**
     * Retry a request with exponential backoff
     *
     * @param callable $request
     * @param int $attempts
     * @return array
     * @throws Exception
     */
    private function retry(callable $request, int $attempts = 3): array
    {
        $delay = $this->config['retry']['initial_delay'] ?? 1;
        $maxDelay = $this->config['retry']['max_delay'] ?? 8;
        $multiplier = $this->config['retry']['multiplier'] ?? 2;

        for ($i = 1; $i <= $attempts; $i++) {
            try {
                return $request();
            } catch (Exception $e) {
                if ($i === $attempts) {
                    $this->log('error', 'All retry attempts failed', [
                        'attempts' => $attempts,
                        'final_error' => $e->getMessage()
                    ]);
                    throw $e;
                }

                $currentDelay = min($delay * pow($multiplier, $i - 1), $maxDelay);
                
                $this->log('warning', 'Request failed, retrying', [
                    'attempt' => $i,
                    'max_attempts' => $attempts,
                    'delay_seconds' => $currentDelay,
                    'error' => $e->getMessage()
                ]);

                sleep($currentDelay);
            }
        }

        throw new Exception('Retry mechanism failed');
    }

    /**
     * Clear authentication cache
     *
     * @return void
     */
    private function clearAuthCache(): void
    {
        $cacheKey = $this->cachePrefix . 'auth_token';
        Cache::forget($cacheKey);
        $this->authToken = null;
    }

    /**
     * Validate configuration
     *
     * @throws Exception
     */
    private function validateConfiguration(): void
    {
        if (empty($this->config['api_url'])) {
            throw new Exception('Salas API URL not configured. Please set SALAS_API_URL environment variable.');
        }

        if (!filter_var($this->config['api_url'], FILTER_VALIDATE_URL)) {
            throw new Exception('Invalid Salas API URL format.');
        }
    }

    /**
     * Log a message if logging is enabled
     *
     * @param string $level
     * @param string $message
     * @param array $context
     * @return void
     */
    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->config['enable_logging'] ?? true) {
            $context['component'] = 'SalasApiClient';
            Log::$level($message, $context);
        }
    }

    /**
     * Log request details if enabled
     *
     * @param string $method
     * @param string $endpoint
     * @param array $data
     * @return void
     */
    private function logRequest(string $method, string $endpoint, array $data): void
    {
        if ($this->config['log_requests'] ?? false) {
            $this->log('debug', 'Making API request', [
                'method' => $method,
                'endpoint' => $endpoint,
                'data_keys' => array_keys($data),
                'url' => rtrim($this->config['api_url'], '/') . $endpoint
            ]);
        }
    }

    /**
     * Log response details if enabled
     *
     * @param int $status
     * @param array|null $response
     * @return void
     */
    private function logResponse(int $status, ?array $response): void
    {
        if ($this->config['log_responses'] ?? false) {
            $this->log('debug', 'Received API response', [
                'status' => $status,
                'has_data' => isset($response['data']),
                'has_error' => isset($response['error']),
                'response_keys' => $response ? array_keys($response) : []
            ]);
        }
    }

    /**
     * Get current authentication status
     *
     * @return bool
     */
    public function isAuthenticated(): bool
    {
        return $this->authToken !== null;
    }

    /**
     * Get API configuration
     *
     * @return array
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Test API connectivity
     *
     * @return bool
     */
    public function testConnection(): bool
    {
        try {
            $this->authenticate();
            // Try to get user info to verify token works
            $this->get('/api/v1/auth/user');
            return true;
        } catch (Exception $e) {
            $this->log('error', 'Connection test failed', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}