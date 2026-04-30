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
];
