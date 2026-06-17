<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Uspdev\Replicado\DB;
use App\Models\SchoolClass;
use App\Models\SchoolTerm;
use Exception;

/**
 * Serviço para cálculo de média histórica de matriculados em turmas de 1º semestre.
 *
 * O histórico consolidado utiliza o número de MATRICULADOS (nummtr), pois reflete
 * o dado final após fechamento. O valor atual da turma (estmtr) usa INSCRITOS
 * (numins), calculado previamente por calcEstimadedEnrollment. Quando a diferença
 * entre inscritos atuais e a média histórica de matriculados supera o threshold,
 * o estmtr é corrigido para a média histórica.
 *
 * IMPORTANTE: turmas com observações especiais em obstur (ex: dependentes,
 * horário fictício, aproveitamento de estudos) são ignoradas tanto no cálculo
 * da média histórica quanto na aplicação da correção.
 */
class HistoricalEnrollmentService
{
    /**
     * Threshold percentual para sobrescrita (configurável via env).
     */
    private float $thresholdPercent = 7.0;

    /**
     * Número mínimo de anos históricos necessários para confiabilidade.
     */
    private int $minHistoricalYears = 2;

    /**
     * Método de estimativa de demanda para o payload do solver.
     */
    private string $estimationMethod = 'average_plus_stddev';

    /**
     * Multiplicador do desvio padrão quando estimationMethod = 'average_plus_stddev'.
     */
    private float $stddevMultiplier = 3.0;

    /**
     * Teto máximo para a estimativa histórica de demanda.
     */
    private int $cap = 100;

    /**
     * Número de anos anteriores a consultar no cálculo da média histórica.
     */
    private int $yearsToLookBack = 5;

    /**
     * Palavras-chave em obstur que indicam turmas especiais e devem ser excluídas.
     */
    private array $exclusionObsturKeywords = [
        'dependente',
        'fictício',
        'ficticio',
        'apenas para',
        'aproveitamento',
        'PCoC',
        'divididas',
    ];

    public function __construct(array $configOverrides = [])
    {
        $this->thresholdPercent = (float) ($configOverrides['historical_threshold_percent']
            ?? config('alocacao.historical_threshold_percent', 7.0));
        $this->minHistoricalYears = (int) ($configOverrides['historical_min_years']
            ?? config('alocacao.historical_min_years', 2));
        $this->estimationMethod = (string) ($configOverrides['historical_estimation_method']
            ?? config('alocacao.historical_estimation_method', 'average_plus_stddev'));
        $this->stddevMultiplier = (float) ($configOverrides['historical_stddev_multiplier']
            ?? config('alocacao.historical_stddev_multiplier', 3.0));
        $this->cap = (int) ($configOverrides['historical_cap']
            ?? config('alocacao.historical_cap', 100));
        $this->yearsToLookBack = (int) ($configOverrides['historical_lookback_years']
            ?? config('alocacao.historical_lookback_years', 5));
    }

    /**
     * Calcula a média histórica de matriculados para uma disciplina/turma em 1º semestres anteriores.
     *
     * A busca histórica usa o sufixo da turma (últimos 2 dígitos do codtur), garantindo que
     * apenas turmas com o mesmo número sejam comparadas ao longo dos anos.
     *
     * @param string $coddis Código da disciplina
     * @param string $codtur Código completo da turma (AAAASXX)
     * @param int|null $currentYear Ano atual (null = ano do período letivo mais recente)
     * @return array{
     *   average: float|null,
     *   samples: int,
     *   years: int[]
     * }
     */
    public function calculateHistoricalAverage(string $coddis, string $codtur, ?int $currentYear = null): array
    {
        $result = [
            'average' => null,
            'samples' => 0,
            'years' => [],
        ];

        if (!$currentYear) {
            $latestTerm = SchoolTerm::getLatest();
            $currentYear = $latestTerm ? (int) $latestTerm->year : (int) date('Y');
        }

        $codturSuffix = substr($codtur, -2);
        $historicalData = $this->fetchHistoricalEnrollments($coddis, $codturSuffix, $currentYear);

        if (empty($historicalData)) {
            Log::info('HistoricalEnrollmentService: no historical data found', [
                'coddis' => $coddis,
                'codtur_suffix' => $codturSuffix,
                'current_year' => $currentYear,
            ]);
            return $result;
        }

        $enrollments = array_column($historicalData, 'total_matriculados');
        $average = array_sum($enrollments) / count($enrollments);

        $result['average'] = round($average, 2);
        $result['samples'] = count($historicalData);
        $result['years'] = array_column($historicalData, 'year');

        Log::info('HistoricalEnrollmentService: calculation completed', [
            'coddis' => $coddis,
            'codtur_suffix' => $codturSuffix,
            'average' => $result['average'],
            'samples' => $result['samples'],
            'years' => $result['years'],
        ]);

        return $result;
    }

    /**
     * Calcula estatísticas históricas completas (média, DP, amostras, anos) para uma turma.
     *
     * @param string $coddis Código da disciplina
     * @param string $codtur Código completo da turma (AAAASXX)
     * @param int|null $currentYear Ano atual
     * @return array{
     *   average: float|null,
     *   stddev: float|null,
     *   samples: int,
     *   years: int[]
     * }
     */
    public function calculateHistoricalStats(string $coddis, string $codtur, ?int $currentYear = null): array
    {
        $result = [
            'average' => null,
            'stddev' => null,
            'samples' => 0,
            'years' => [],
        ];

        if (!$currentYear) {
            $latestTerm = SchoolTerm::getLatest();
            $currentYear = $latestTerm ? (int) $latestTerm->year : (int) date('Y');
        }

        $codturSuffix = substr($codtur, -2);
        $historicalData = $this->fetchHistoricalEnrollments($coddis, $codturSuffix, $currentYear);

        if (empty($historicalData)) {
            return $result;
        }

        $enrollments = array_column($historicalData, 'total_matriculados');
        $average = array_sum($enrollments) / count($enrollments);

        $variance = 0.0;
        if (count($enrollments) >= 2) {
            $variance = array_sum(array_map(fn ($x) => pow($x - $average, 2), $enrollments)) / count($enrollments);
        }
        $stddev = sqrt($variance);

        $result['average'] = round($average, 2);
        $result['stddev'] = round($stddev, 2);
        $result['samples'] = count($historicalData);
        $result['years'] = array_column($historicalData, 'year');

        return $result;
    }

    /**
     * Calcula a demanda ajustada para o payload do solver sem alterar o banco de dados.
     *
     * Aplica a estimativa histórica apenas quando:
     * - A turma é de 1º semestre obrigatório de graduação do IME;
     * - Não possui observações especiais em obstur;
     * - Há dados históricos suficientes;
     * - O estmtr atual está subdimensionado em mais do que thresholdPercent.
     *
     * O método de estimativa é configurável. Para 'average_plus_stddev', usa
     * min(média + (multiplicador * DP), cap). Outros métodos desativam o ajuste.
     *
     * @param SchoolClass $schoolClass
     * @return array{
     *   demand: int|null,
     *   applied: bool,
     *   metadata: array<string, mixed>|null
     * }
     */
    public function calculateAdjustedDemand(SchoolClass $schoolClass): array
    {
        $result = [
            'demand' => $schoolClass->estmtr,
            'applied' => false,
            'metadata' => null,
        ];

        // Só aplica para turmas de Graduação no 1º semestre
        if (!$this->isFirstSemesterClass($schoolClass)) {
            return $result;
        }

        // Ignora turmas com observações especiais
        if ($this->hasSpecialObstur($schoolClass)) {
            return $result;
        }

        $year = (int) substr($schoolClass->codtur, 0, 4);
        $stats = $this->calculateHistoricalStats($schoolClass->coddis, $schoolClass->codtur, $year);

        // Não aplica sem dados históricos suficientes
        if ($stats['samples'] < $this->minHistoricalYears || $stats['average'] === null || $stats['average'] <= 0) {
            return $result;
        }

        $currentEnrollment = $schoolClass->estmtr ?? 0;

        // Só corrige subdimensionamento
        $deviationPercent = (($stats['average'] - $currentEnrollment) / $stats['average']) * 100;
        if ($deviationPercent <= $this->thresholdPercent) {
            return $result;
        }

        // Se método não for o esperado, mantém estmtr
        if ($this->estimationMethod !== 'average_plus_stddev') {
            return $result;
        }

        $stddev = $stats['stddev'] ?? 0;
        $estimatedDemand = (int) round($stats['average'] + ($stddev * $this->stddevMultiplier));
        $estimatedDemand = min($estimatedDemand, $this->cap);

        // Não aplica se a estimativa for menor que o atual (proteção extra)
        if ($estimatedDemand <= $currentEnrollment) {
            return $result;
        }

        $result['demand'] = $estimatedDemand;
        $result['applied'] = true;
        $result['metadata'] = [
            'method' => $this->estimationMethod,
            'average' => $stats['average'],
            'stddev' => $stddev,
            'stddev_multiplier' => $this->stddevMultiplier,
            'cap' => $this->cap,
            'current' => $currentEnrollment,
            'deviation_percent' => round($deviationPercent, 2),
            'threshold' => $this->thresholdPercent,
            'years' => $stats['years'],
            'samples' => $stats['samples'],
        ];

        Log::info('HistoricalEnrollmentService: calculated adjusted demand for payload', [
            'coddis' => $schoolClass->coddis,
            'codtur' => $schoolClass->codtur,
            'old_estmtr' => $currentEnrollment,
            'new_demand' => $estimatedDemand,
        ]);

        return $result;
    }

    /**
     * Aplica a média histórica a uma SchoolClass caso os critérios sejam atendidos.
     *
     * A correção só é aplicada em caso de SUBDIMENSIONAMENTO (inscritos atuais
     * menores que a média histórica). Turmas com observações especiais em obstur
     * são ignoradas completamente.
     *
     * @param SchoolClass $schoolClass
     * @param bool $force Forçar recálculo mesmo que já tenha sido aplicado
     * @param bool $dryRun Quando true, calcula mas não persiste no banco
     * @return bool True se foi sobrescrito (ou deveria ser, no dry-run)
     */
    public function applyToSchoolClass(SchoolClass $schoolClass, bool $force = false, bool $dryRun = false): bool
    {
        // Só aplica para turmas de Graduação no 1º semestre
        if (!$this->isFirstSemesterClass($schoolClass)) {
            return false;
        }

        // Ignora turmas com observações especiais (ex: dependentes, fictícias, etc.)
        if ($this->hasSpecialObstur($schoolClass)) {
            return false;
        }

        // Se já foi aplicado e não é force, pula
        if (!$force && $schoolClass->historical_avg_applied_at) {
            return false;
        }

        $year = (int) substr($schoolClass->codtur, 0, 4);
        $calculation = $this->calculateHistoricalAverage($schoolClass->coddis, $schoolClass->codtur, $year);

        // Não aplica sem dados históricos suficientes
        if ($calculation['samples'] < $this->minHistoricalYears) {
            return false;
        }

        // O valor atual vem de calcEstimadedEnrollment (inscritos), que roda antes deste serviço
        $currentEnrollment = $schoolClass->estmtr;

        if ($calculation['average'] !== null && $currentEnrollment !== null && $calculation['average'] > 0) {
            // Só corrige subdimensionamento (inscritos atuais < média histórica)
            $deviationPercent = (($calculation['average'] - $currentEnrollment) / $calculation['average']) * 100;
            $shouldOverride = $deviationPercent > $this->thresholdPercent;

            if ($shouldOverride) {
                $recommendedEnrollment = (int) round($calculation['average']);
                $schoolClass->estmtr = $recommendedEnrollment;
                $schoolClass->historical_avg_applied_at = now();
                $schoolClass->historical_avg_metadata = [
                    'average' => $calculation['average'],
                    'current' => $currentEnrollment,
                    'deviation_percent' => round($deviationPercent, 2),
                    'threshold' => $this->thresholdPercent,
                    'years' => $calculation['years'],
                    'samples' => $calculation['samples'],
                ];

                if (!$dryRun) {
                    $schoolClass->save();

                    Log::info('HistoricalEnrollmentService: applied historical average', [
                        'coddis' => $schoolClass->coddis,
                        'codtur' => $schoolClass->codtur,
                        'old_estmtr' => $currentEnrollment,
                        'new_estmtr' => $recommendedEnrollment,
                    ]);
                }

                return true;
            }
        }

        return false;
    }

    /**
     * Verifica se uma turma é de 1º semestre obrigatória de graduação do IME.
     *
     * No Replicado, turmas dos cursos do IME têm os dois últimos dígitos do codtur
     * (sufixo da turma) >= 40. Turmas com sufixo < 40 pertencem a outras unidades
     * e não devem ser alocadas no prédio do IME.
     */
    public function isFirstSemesterClass(SchoolClass $schoolClass): bool
    {
        if ($schoolClass->tiptur !== 'Graduação') {
            return false;
        }

        // codtur formato AAAASXX, onde S=1 indica 1º semestre
        $semesterDigit = substr($schoolClass->codtur, 4, 1);
        if ($semesterDigit !== '1') {
            return false;
        }

        // Só processa turmas do IME (sufixo >= 40)
        $codturSuffix = (int) substr($schoolClass->codtur, -2);
        if ($codturSuffix < 40) {
            return false;
        }

        // Deve possuir courseinformation de 1º semestre obrigatório
        return $schoolClass->courseinformations()
            ->where('numsemidl', 1)
            ->where('tipobg', 'O')
            ->exists();
    }

    /**
     * Verifica se a turma possui obstur indicando que é uma turma especial
     * e não deve ser comparada com a média histórica de turmas regulares.
     */
    public function hasSpecialObstur(SchoolClass $schoolClass): bool
    {
        // Como SchoolClass não armazena obstur localmente, buscamos no Replicado
        try {
            $query = "SELECT T.obstur FROM TURMAGR AS T
                      WHERE T.coddis = :coddis
                        AND T.codtur = :codtur
                        AND T.statur IN ('A', 'C')
                        AND T.verdis = (SELECT MAX(T2.verdis)
                                        FROM TURMAGR AS T2
                                        WHERE T2.coddis = :coddis
                                          AND T2.codtur = T.codtur
                                          AND T2.statur IN ('A', 'C'))";
            $param = [
                'coddis' => $schoolClass->coddis,
                'codtur' => $schoolClass->codtur,
            ];
            $res = DB::fetchAll($query, $param);
            if (!empty($res) && !empty($res[0]['obstur'])) {
                $obsturLower = mb_strtolower($res[0]['obstur']);
                foreach ($this->exclusionObsturKeywords as $keyword) {
                    if (mb_strpos($obsturLower, mb_strtolower($keyword)) !== false) {
                        Log::info('HistoricalEnrollmentService: skipping special class due to obstur', [
                            'coddis' => $schoolClass->coddis,
                            'codtur' => $schoolClass->codtur,
                            'obstur' => $res[0]['obstur'],
                            'matched_keyword' => $keyword,
                        ]);
                        return true;
                    }
                }
            }
        } catch (Exception $e) {
            Log::warning('HistoricalEnrollmentService: failed to check obstur', [
                'coddis' => $schoolClass->coddis,
                'codtur' => $schoolClass->codtur,
                'error' => $e->getMessage(),
            ]);
        }

        return false;
    }

    /**
     * Busca matrículas históricas da disciplina/turma em 1º semestres anteriores.
     *
     * O filtro leva em conta o sufixo do codtur (últimos 2 dígitos), garantindo que
     * apenas turmas com o mesmo número sejam comparadas ao longo dos anos.
     * Turmas com obstur indicando exceção são excluídas do cálculo da média.
     *
     * @param string $coddis Código da disciplina
     * @param string $codturSuffix Sufixo da turma (últimos 2 dígitos do codtur)
     * @param int $currentYear Ano da turma atual
     * @return array<int, array{year: int, codtur: string, total_matriculados: int}>
     */
    private function fetchHistoricalEnrollments(string $coddis, string $codturSuffix, int $currentYear): array
    {
        $results = [];

        for ($offset = 1; $offset <= $this->yearsToLookBack; $offset++) {
            $year = $currentYear - $offset;
            $codtur = $year . '1' . $codturSuffix;

            $query = "SELECT T.codtur, T.obstur,
                             (ISNULL(T.nummtr,0) + ISNULL(T.nummtrturcpl,0) + ISNULL(T.nummtropt,0) + ISNULL(T.nummtrecr,0) + ISNULL(T.nummtroptlre,0)) AS TOTALMATRICULADOS
                      FROM TURMAGR AS T
                      WHERE T.coddis = :coddis
                        AND T.codtur = :codtur
                        AND T.statur IN ('A', 'C')
                        AND T.verdis = (SELECT MAX(T2.verdis)
                                        FROM TURMAGR AS T2
                                        WHERE T2.coddis = :coddis
                                          AND T2.codtur = T.codtur
                                          AND T2.statur IN ('A', 'C'))";

            $param = [
                'coddis' => $coddis,
                'codtur' => $codtur,
            ];

            try {
                $turmas = DB::fetchAll($query, $param);
            } catch (Exception $e) {
                Log::error('HistoricalEnrollmentService: query failed', [
                    'coddis' => $coddis,
                    'codtur' => $codtur,
                    'year' => $year,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            foreach ($turmas as $turma) {
                // Ignora turmas especiais no histórico
                if (!empty($turma['obstur'])) {
                    $obsturLower = mb_strtolower($turma['obstur']);
                    $isSpecial = false;
                    foreach ($this->exclusionObsturKeywords as $keyword) {
                        if (mb_strpos($obsturLower, mb_strtolower($keyword)) !== false) {
                            $isSpecial = true;
                            break;
                        }
                    }
                    if ($isSpecial) {
                        continue;
                    }
                }

                $total = (int) ($turma['TOTALMATRICULADOS'] ?? 0);
                if ($total > 0) {
                    $results[] = [
                        'year' => $year,
                        'codtur' => $turma['codtur'],
                        'total_matriculados' => $total,
                    ];
                }
            }
        }

        return $results;
    }
}
