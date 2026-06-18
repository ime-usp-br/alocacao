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
    | Método de estimativa de demanda para turmas de 1º semestre no payload do
    | solver. 'average_plus_stddev' usa média + (multiplicador * desvio padrão).
    | Outros valores desativam a estimativa ajustada (usa estmtr puro).
    |--------------------------------------------------------------------------
    */
    'historical_estimation_method' => env('HISTORICAL_ESTIMATION_METHOD', 'average_plus_stddev'),

    /*
    |--------------------------------------------------------------------------
    | Multiplicador do desvio padrão quando historical_estimation_method for
    | 'average_plus_stddev'. Valores maiores geram estimativas mais conservadoras.
    |--------------------------------------------------------------------------
    */
    'historical_stddev_multiplier' => (float) env('HISTORICAL_STDDEV_MULTIPLIER', 3.0),

    /*
    |--------------------------------------------------------------------------
    | Teto máximo para a estimativa histórica de demanda no payload do solver.
    | Evita que turmas muito grandes sejam enviadas para salas inadequadas.
    |--------------------------------------------------------------------------
    */
    'historical_cap' => (int) env('HISTORICAL_CAP', 100),

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
        'waste_penalty' => (float) env('ROOM_ALLOCATION_WASTE_PENALTY', 1.0),
        'claustrophobia_penalty' => (float) env('ROOM_ALLOCATION_CLAUSTROPHOBIA_PENALTY', 1.0),
        'comfort_zone_min_percent' => (float) env('ROOM_ALLOCATION_COMFORT_ZONE_MIN_PERCENT', 10.0),
        'comfort_zone_max_percent' => (float) env('ROOM_ALLOCATION_COMFORT_ZONE_MAX_PERCENT', 25.0),
        'split_class_penalty' => (float) env('ROOM_ALLOCATION_SPLIT_CLASS_PENALTY', 1.0),
        'split_cohort_penalty' => (float) env('ROOM_ALLOCATION_SPLIT_COHORT_PENALTY', 1.0),
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
