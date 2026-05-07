<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Threshold percentual para sobrescrita de inscritos por média histórica.
    | Se a diferença percentual entre inscritos atuais e média histórica
    | for maior que este valor, o sistema sobrescreve com a média.
    |--------------------------------------------------------------------------
    */
    'historical_threshold_percent' => (float) env('HISTORICAL_THRESHOLD_PERCENT', 7.0),

    /*
    |--------------------------------------------------------------------------
    | Número de anos anteriores a consultar no cálculo da média histórica.
    |--------------------------------------------------------------------------
    */
    'historical_lookback_years' => (int) env('HISTORICAL_LOOKBACK_YEARS', 5),

    /*
    |--------------------------------------------------------------------------
    | Número mínimo de anos históricos com dados para considerar a média
    | confiável e permitir a sobrescrita.
    |--------------------------------------------------------------------------
    */
    'historical_min_years' => (int) env('HISTORICAL_MIN_YEARS', 2),

    /*
    |--------------------------------------------------------------------------
    | Configurações do solver de alocação de salas (OR-Tools Python).
    |--------------------------------------------------------------------------
    */
    'room_allocation' => [
        'strict_capacity' => (bool) env('ROOM_ALLOCATION_STRICT_CAPACITY', true),
        'block_b_restriction_for_pos' => (bool) env('ROOM_ALLOCATION_BLOCK_B_POS', true),
        'block_a_restriction_for_freshmen' => (bool) env('ROOM_ALLOCATION_BLOCK_A_FRESHMEN', true),
        'undergrad_in_block_a_penalty' => (float) env('ROOM_ALLOCATION_UNDERGRAD_BLOCK_A_PENALTY', 500.0),
        'pos_in_block_b_penalty' => (float) env('ROOM_ALLOCATION_POS_BLOCK_B_PENALTY', 500.0),
        'wasted_seats_weight' => (float) env('ROOM_ALLOCATION_WASTED_SEATS_WEIGHT', 1.0),
        'unassigned_penalty' => (float) env('ROOM_ALLOCATION_UNASSIGNED_PENALTY', 1000.0),
        'priority_weight' => (float) env('ROOM_ALLOCATION_PRIORITY_WEIGHT', 0.0),
        'time_limit_seconds' => (int) env('ROOM_ALLOCATION_TIME_LIMIT', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Configurações do microserviço Python de otimização (alocacao-solver).
    |--------------------------------------------------------------------------
    */
    'solver' => [
        'url' => env('ALOCACAO_SOLVER_URL'),
        'api_token' => env('ALOCACAO_SOLVER_API_TOKEN'),
        'timeout' => (int) env('ALOCACAO_SOLVER_TIMEOUT', 60),
        'verify_ssl' => (bool) env('ALOCACAO_SOLVER_VERIFY_SSL', true),
    ],
];
