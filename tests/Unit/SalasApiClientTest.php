<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\SalasApiClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Exception;

class SalasApiClientTest extends TestCase
{
    private $client;
    private $config;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Set up test configuration
        $this->config = [
            'api_url' => 'https://test-salas.ime.usp.br',
            'credentials' => [
                'email' => 'test@ime.usp.br',
                'password' => 'test-password'
            ],
            'timeout' => [
                'connection' => 5,
                'request' => 10,
            ],
            'retry' => [
                'max_attempts' => 2,
                'initial_delay' => 1,
                'max_delay' => 4,
                'multiplier' => 2,
            ],
            'rate_limiting' => [
                'requests_per_minute' => 30,
            ],
            'cache' => [
                'enabled' => false, // Disable caching in tests to avoid Carbon issues
                'ttl' => [
                    'auth_token' => 300,
                ],
                'prefix' => 'test_salas_api:',
            ],
            'enable_logging' => false,
            'log_requests' => false,
            'log_responses' => false,
        ];

        Config::set('salas', $this->config);
        
        $this->client = new SalasApiClient();
    }

    public function test_constructor_validates_configuration()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Salas API URL not configured');
        
        Config::set('salas.api_url', '');
        new SalasApiClient();
    }

    public function test_constructor_validates_url_format()
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid Salas API URL format');
        
        Config::set('salas.api_url', 'invalid-url');
        new SalasApiClient();
    }

    public function test_authenticate_success()
    {
        Http::fake([
            'test-salas.ime.usp.br/api/v1/auth/token' => Http::response([
                'data' => [
                    'token' => 'test-token-123',
                    'user' => [
                        'email' => 'test@ime.usp.br'
                    ]
                ]
            ], 201)
        ]);

        $token = $this->client->authenticate();
        
        $this->assertEquals('test-token-123', $token);
        $this->assertTrue($this->client->isAuthenticated());
    }

    public function test_authenticate_with_missing_credentials()
    {
        Config::set('salas.credentials.email', '');
        Config::set('salas.credentials.password', '');
        
        $client = new SalasApiClient();
        
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Salas API credentials not configured');
        
        $client->authenticate();
    }

    public function test_authenticate_with_invalid_response()
    {
        Http::fake([
            'test-salas.ime.usp.br/api/v1/auth/token' => Http::response([
                'error' => 'Invalid credentials'
            ], 401)
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Authentication failed - token may have expired');
        
        $this->client->authenticate();
    }

    public function test_get_request_success()
    {
        // Mock authentication
        Http::fake([
            'test-salas.ime.usp.br/api/v1/auth/token' => Http::response([
                'data' => [
                    'token' => 'test-token-123',
                    'user' => ['email' => 'test@ime.usp.br']
                ]
            ], 201),
            'test-salas.ime.usp.br/api/v1/salas' => Http::response([
                'data' => [
                    ['id' => 1, 'nome' => 'Sala 01']
                ]
            ], 200)
        ]);

        $response = $this->client->get('/api/v1/salas');
        
        $this->assertIsArray($response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals(1, $response['data'][0]['id']);
    }

    public function test_post_request_success()
    {
        // Mock authentication
        Http::fake([
            'test-salas.ime.usp.br/api/v1/auth/token' => Http::response([
                'data' => [
                    'token' => 'test-token-123',
                    'user' => ['email' => 'test@ime.usp.br']
                ]
            ], 201),
            'test-salas.ime.usp.br/api/v1/reservas' => Http::response([
                'data' => [
                    'id' => 123,
                    'nome' => 'Test Reservation',
                    'status' => 'aprovada'
                ]
            ], 201)
        ]);

        $response = $this->client->post('/api/v1/reservas', [
            'nome' => 'Test Reservation',
            'sala_id' => 1,
            'data' => '2024-09-01'
        ]);
        
        $this->assertIsArray($response);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals(123, $response['data']['id']);
    }

    public function test_handles_422_validation_errors()
    {
        // Mock authentication
        Http::fake([
            'test-salas.ime.usp.br/api/v1/auth/token' => Http::response([
                'data' => [
                    'token' => 'test-token-123',
                    'user' => ['email' => 'test@ime.usp.br']
                ]
            ], 201),
            'test-salas.ime.usp.br/api/v1/reservas' => Http::response([
                'message' => 'The given data was invalid.',
                'errors' => [
                    'sala_id' => ['The sala id field is required.']
                ]
            ], 422)
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('The given data was invalid.: The sala id field is required.');
        
        $this->client->post('/api/v1/reservas', []);
    }

    public function test_handles_429_rate_limit()
    {
        // Mock authentication
        Http::fake([
            'test-salas.ime.usp.br/api/v1/auth/token' => Http::response([
                'data' => [
                    'token' => 'test-token-123',
                    'user' => ['email' => 'test@ime.usp.br']
                ]
            ], 201),
            'test-salas.ime.usp.br/api/v1/salas' => Http::response([
                'message' => 'Too Many Attempts.'
            ], 429, ['Retry-After' => '1'])
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Rate limit exceeded. Retry after 1 seconds');
        
        $this->client->get('/api/v1/salas');
    }

    public function test_caches_authentication_token()
    {
        // Enable caching for this specific test
        Config::set('salas.cache.enabled', true);
        
        Http::fake([
            'test-salas.ime.usp.br/api/v1/auth/token' => Http::response([
                'data' => [
                    'token' => 'cached-token-123',
                    'user' => ['email' => 'test@ime.usp.br']
                ]
            ], 201)
        ]);

        // Create client with caching enabled
        $client1 = new SalasApiClient();
        
        // First authentication should make HTTP call
        $token1 = $client1->authenticate();
        
        // Create new client instance
        $client2 = new SalasApiClient();
        
        // Second authentication should use cached token
        $token2 = $client2->authenticate();
        
        $this->assertEquals($token1, $token2);
        
        // Should have made only one HTTP call
        Http::assertSentCount(1);
    }

    public function test_test_connection_success()
    {
        Http::fake([
            'test-salas.ime.usp.br/api/v1/auth/token' => Http::response([
                'data' => [
                    'token' => 'test-token-123',
                    'user' => ['email' => 'test@ime.usp.br']
                ]
            ], 201),
            'test-salas.ime.usp.br/api/v1/auth/user' => Http::response([
                'data' => [
                    'id' => 1,
                    'email' => 'test@ime.usp.br'
                ]
            ], 200)
        ]);

        $result = $this->client->testConnection();
        
        $this->assertTrue($result);
    }

    public function test_test_connection_failure()
    {
        Http::fake([
            'test-salas.ime.usp.br/api/v1/auth/token' => Http::response([
                'error' => 'Invalid credentials'
            ], 401)
        ]);

        $result = $this->client->testConnection();
        
        $this->assertFalse($result);
    }

    public function test_get_config_returns_configuration()
    {
        $config = $this->client->getConfig();
        
        $this->assertIsArray($config);
        $this->assertEquals('https://test-salas.ime.usp.br', $config['api_url']);
        $this->assertEquals('test@ime.usp.br', $config['credentials']['email']);
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }
}