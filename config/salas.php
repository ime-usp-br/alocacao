<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Salas API Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the Salas API integration. This replaces direct
    | database writes to the Urano system with modern REST API calls.
    |
    */

    'api_url' => env('SALAS_API_URL', 'https://salas.ime.usp.br'),

    'credentials' => [
        'email' => env('SALAS_API_EMAIL'),
        'password' => env('SALAS_API_PASSWORD'),
    ],

    'timeout' => [
        'connection' => env('SALAS_API_CONNECTION_TIMEOUT', 10),
        'request' => env('SALAS_API_REQUEST_TIMEOUT', 30),
    ],

    'retry' => [
        'max_attempts' => env('SALAS_API_MAX_RETRIES', 3),
        'initial_delay' => env('SALAS_API_RETRY_DELAY', 1),
        'max_delay' => env('SALAS_API_MAX_RETRY_DELAY', 8),
        'multiplier' => 2,
    ],

    'rate_limiting' => [
        'requests_per_minute' => env('SALAS_API_RATE_LIMIT', 30),
        'burst_limit' => env('SALAS_API_BURST_LIMIT', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Feature Flags
    |--------------------------------------------------------------------------
    |
    | Control the integration behavior and enable safe rollback.
    |
    */

    'use_api' => env('SALAS_USE_API', false),
    'fallback_to_urano' => env('SALAS_FALLBACK_TO_URANO', true),
    'enable_logging' => env('SALAS_ENABLE_LOGGING', true),
    'log_requests' => env('SALAS_LOG_REQUESTS', false),
    'log_responses' => env('SALAS_LOG_RESPONSES', false),

    /*
    |--------------------------------------------------------------------------
    | Default Values
    |--------------------------------------------------------------------------
    |
    | Default values for API requests when not specified.
    |
    */

    'defaults' => [
        'finalidade_id' => 1, // Graduação
        'tipo_responsaveis' => 'eu',
        'status' => 'aprovada',
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for caching room information and other API responses.
    |
    */

    'cache' => [
        'enabled' => env('SALAS_CACHE_ENABLED', true),
        'ttl' => [
            'rooms' => env('SALAS_CACHE_ROOMS_TTL', 3600), // 1 hour
            'finalidades' => env('SALAS_CACHE_FINALIDADES_TTL', 86400), // 24 hours
            'auth_token' => env('SALAS_CACHE_TOKEN_TTL', 3600), // 1 hour
        ],
        'prefix' => 'salas_api:',
    ],

    /*
    |--------------------------------------------------------------------------
    | Circuit Breaker Configuration
    |--------------------------------------------------------------------------
    |
    | Circuit breaker settings to handle API failures gracefully.
    |
    */

    'circuit_breaker' => [
        'enabled' => env('SALAS_CIRCUIT_BREAKER_ENABLED', true),
        'failure_threshold' => env('SALAS_CIRCUIT_BREAKER_FAILURES', 5),
        'timeout_duration' => env('SALAS_CIRCUIT_BREAKER_TIMEOUT', 300), // 5 minutes
        'recovery_timeout' => env('SALAS_CIRCUIT_BREAKER_RECOVERY', 30), // 30 seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Room Name Mapping
    |--------------------------------------------------------------------------
    |
    | Special cases for room name conversion from Alocacao to Salas format.
    | This preserves the existing logic from Reservation model.
    |
    */

    'room_mapping' => [
        'special_cases' => [
            'Auditório Jacy Monteiro' => 'AJM',
            'Auditório Antonio Gilioli' => 'AAG',
        ],
        'format_4_chars' => true, // B123 -> B0123 for rooms with 4 chars
    ],

    /*
    |--------------------------------------------------------------------------
    | Error Handling
    |--------------------------------------------------------------------------
    |
    | Configuration for error handling and notifications.
    |
    */

    'error_handling' => [
        'log_errors' => env('SALAS_LOG_ERRORS', true),
        'notify_on_failure' => env('SALAS_NOTIFY_FAILURES', false),
        'notification_channels' => env('SALAS_NOTIFICATION_CHANNELS', 'log'),
        'max_consecutive_failures' => env('SALAS_MAX_FAILURES', 10),
    ],
];