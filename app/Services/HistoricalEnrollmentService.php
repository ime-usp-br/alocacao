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

    public function __construct()
    {
        $this->thresholdPercent = (float) config('alocacao.historical_threshold_percent', 7.0);
        $this->minHistoricalYears = (int) config('alocacao.historical_min_years', 2);
    }

    /**
     * Calcula a média histórica de matriculados para uma disciplina em turmas de 1º semestre.
     *
     * @param string $coddis Código da disciplina
     * @param int|null $currentYear Ano atual (null = ano do período letivo mais recente)
     * @return array{
     *   average: float|null,
     *   samples: int,
     *   years: int[]
     * }
     */
    public function calculateHistoricalAverage(string $coddis, ?int $currentYear = null): array
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

        $historicalData = $this->fetchHistoricalEnrollments($coddis, $currentYear);

        if (empty($historicalData)) {
            Log::info('HistoricalEnrollmentService: no historical data found', [
                'coddis' => $coddis,
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
            'average' => $result['average'],
            'samples' => $result['samples'],
            'years' => $result['years'],
        ]);

        return $result;
    }

    /**
     * Aplica a média histórica a uma SchoolClass caso os critérios sejam atendidos.
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

        // Se já foi aplicado e não é force, pula
        if (!$force && $schoolClass->historical_avg_applied_at) {
            return false;
        }

        $year = (int) substr($schoolClass->codtur, 0, 4);
        $calculation = $this->calculateHistoricalAverage($schoolClass->coddis, $year);

        // Não aplica sem dados históricos suficientes
        if ($calculation['samples'] < $this->minHistoricalYears) {
            return false;
        }

        // O valor atual vem de calcEstimadedEnrollment (inscritos), que roda antes deste serviço
        $currentEnrollment = $schoolClass->estmtr;

        if ($calculation['average'] !== null && $currentEnrollment !== null && $calculation['average'] > 0) {
            $deviationPercent = abs(($currentEnrollment - $calculation['average']) / $calculation['average']) * 100;
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
     * Verifica se uma turma é de 1º semestre obrigatória de graduação.
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

        // Deve possuir courseinformation de 1º semestre obrigatório
        return $schoolClass->courseinformations()
            ->where('numsemidl', 1)
            ->where('tipobg', 'O')
            ->exists();
    }

    /**
     * Busca matrículas históricas da disciplina em 1º semestres anteriores.
     *
     * @return array<int, array{year: int, codtur: string, total_matriculados: int}>
     */
    private function fetchHistoricalEnrollments(string $coddis, int $currentYear): array
    {
        $results = [];
        $yearsToLookBack = (int) config('alocacao.historical_lookback_years', 5);

        for ($offset = 1; $offset <= $yearsToLookBack; $offset++) {
            $year = $currentYear - $offset;
            $codturPattern = $year . '1%';

            $query = "SELECT T.codtur,
                             (T.nummtr + T.nummtrturcpl + T.nummtropt + T.nummtrecr + T.nummtroptlre) AS TOTALMATRICULADOS
                      FROM TURMAGR AS T
                      WHERE T.coddis = :coddis
                        AND T.codtur LIKE :codtur
                        AND T.statur = :statur
                        AND T.verdis = (SELECT MAX(T2.verdis)
                                        FROM TURMAGR AS T2
                                        WHERE T2.coddis = :coddis
                                          AND T2.codtur = T.codtur
                                          AND T2.statur = :statur)";

            $param = [
                'coddis' => $coddis,
                'codtur' => $codturPattern,
                'statur' => 'A',
            ];

            try {
                $turmas = DB::fetchAll($query, $param);
            } catch (Exception $e) {
                Log::error('HistoricalEnrollmentService: query failed', [
                    'coddis' => $coddis,
                    'year' => $year,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            foreach ($turmas as $turma) {
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
