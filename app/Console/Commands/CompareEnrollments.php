<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\SchoolTerm;
use App\Models\SchoolClass;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

class CompareEnrollments extends Command
{
    protected $signature = 'schoolclass:compare-enrollments
                            {--skip-update : Não busca estmtr no Replicado antes de comparar; usa os valores já gravados no BD (mais rápido)}
                            {--top=-1 : Quantos piores casos exibir no ranking; -1 para todos (default: todos)}
                            {--json : Devolve a saída em JSON}
                            {--markdown : Devolve a saída em Markdown (para colar em e-mails com conversor MD)}
                            {--output= : Salva o relatório (amigável ou markdown) em um arquivo de texto}
                            {--years=3 : Número de anos anteriores usados como baseline (média do mesmo período; default 3)}
                            {--tipo= : Filtra turmas pelo tipo (ex: "Graduação" ou "Pós Graduação")}
                            {--include-externa : Inclui turmas externas no comparativo (por padrão só internas)}
                            {--min-drop=20 : Queda percentual mínima para marcar uma turma como subdimensionada}
                            {--min-enrollment=5 : Ignora pares cuja baseline média seja menor que este valor}
                            {--year= : Ano do semestre atual (default: período mais recente)}
                            {--period= : Período do semestre atual (ex: "1° Semestre"; default: mais recente)}
                            {--omit-skipped : Não lista as turmas que tinham equivalente mas foram excluídas (estmtr nulo ou baseline < --min-enrollment)}';

    protected $description = 'Compara o número de inscritos (estmtr) das turmas do semestre atual com as turmas equivalentes do(s) ano(s) anterior(es) (mesma disciplina + mesmo sufixo de turma), avaliando estatisticamente se estão subdimensionadas.';

    public function handle()
    {
        $currentTerm = $this->resolveCurrentTerm();
        if (!$currentTerm) {
            $this->error('Nenhum período letivo atual encontrado. Cadastre um período primeiro ou use --year/--period.');
            return Command::FAILURE;
        }

        $years = max(1, (int) $this->option('years'));
        $baselineTerms = $this->resolveBaselineTerms($currentTerm, $years);
        if ($baselineTerms->isEmpty()) {
            $this->error("Não foi encontrado nenhum período letivo correspondente dos últimos {$years} ano(s) (período {$currentTerm->period}).");
            return Command::FAILURE;
        }

        $options = [
            'top' => (int) $this->option('top'),
            'force_update' => !(bool) $this->option('skip-update'),
            'json' => (bool) $this->option('json'),
            'markdown' => (bool) $this->option('markdown'),
            'output' => $this->option('output'),
            'tipo' => $this->option('tipo'),
            'include_externa' => (bool) $this->option('include-externa'),
            'omit_skipped' => (bool) $this->option('omit-skipped'),
            'min_drop' => (float) $this->option('min-drop'),
            'min_enrollment' => (float) $this->option('min-enrollment'),
            'years' => $years,
        ];

        if ($options['json'] && $options['markdown']) {
            $this->error('As opções --json e --markdown são mutuamente exclusivas.');
            return Command::FAILURE;
        }
        if ($options['json'] && $options['output']) {
            $this->error('As opções --json e --output são mutuamente exclusivas.');
            return Command::FAILURE;
        }

        $currentClasses = null;
        $baselineClassesByTerm = [];

        if ($options['force_update']) {
            $this->info('Buscando inscritos (estmtr) no Replicado para comparação (sem gravar no BD)...');
            $classesByTerm = $this->forceUpdateEnrollments(collect([$currentTerm])->merge($baselineTerms), $options);
            $currentClasses = $classesByTerm[$currentTerm->id] ?? $this->loadClasses($currentTerm, $options);
            foreach ($baselineTerms as $bt) {
                $baselineClassesByTerm[$bt->id] = $classesByTerm[$bt->id] ?? $this->loadClasses($bt, $options);
            }
        } else {
            $currentClasses = $this->loadClasses($currentTerm, $options);
            foreach ($baselineTerms as $bt) {
                $baselineClassesByTerm[$bt->id] = $this->loadClasses($bt, $options);
            }
        }

        $pairs = $this->buildComparablePairs($currentClasses, $baselineClassesByTerm, $baselineTerms, $options['min_enrollment']);
        $unmatched = $this->buildUnmatched($currentClasses, $baselineClassesByTerm, $options['min_enrollment']);
        $skipped = $this->buildSkipped($currentClasses, $baselineClassesByTerm, $baselineTerms, $options['min_enrollment']);

        $summary = $this->computeSummary($pairs, $options);

        $ranking = $pairs->sortByDesc('drop_pct')
            ->when($options['top'] < 0, fn($c) => $c, fn($c) => $c->take($options['top']))
            ->values();

        $totals = $this->buildTotals(
            $currentTerm,
            $currentClasses,
            $baselineTerms,
            $baselineClassesByTerm,
            $pairs,
            $unmatched,
            $skipped,
            $options
        );

        $payload = $this->buildPayload($currentTerm, $baselineTerms, $options, $summary, $ranking, $pairs, $unmatched, $skipped, $totals);

        if ($options['json']) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }

        $report = $options['markdown']
            ? $this->renderMarkdown($payload)
            : $this->renderFriendly($payload);

        if ($options['output']) {
            File::put($options['output'], $report);
            $this->info("Relatório salvo em: {$options['output']}");
        } else {
            $this->output->write($report);
        }

        return Command::SUCCESS;
    }

    private function resolveCurrentTerm(): ?SchoolTerm
    {
        $year = $this->option('year');
        $period = $this->option('period');

        if ($year && $period) {
            return SchoolTerm::where('year', $year)->where('period', $period)->first();
        }
        if ($year xor $period) {
            $this->error('Informe --year E --period juntos, ou nenhum dos dois (usa o mais recente).');
            return null;
        }

        return SchoolTerm::getLatest();
    }

    private function resolveBaselineTerms(SchoolTerm $currentTerm, int $years): Collection
    {
        $terms = collect();
        for ($i = 1; $i <= $years; $i++) {
            $t = SchoolTerm::where('year', $currentTerm->year - $i)
                ->where('period', $currentTerm->period)
                ->first();
            if ($t) {
                $terms->push($t);
            }
        }
        return $terms;
    }

    private function loadClasses(SchoolTerm $term, array $options): Collection
    {
        $query = SchoolClass::whereBelongsTo($term);

        if ($options['tipo']) {
            $query->where('tiptur', $options['tipo']);
        }
        if (!$options['include_externa']) {
            $query->where('externa', false);
        }

        return $query->get();
    }

    private function forceUpdateEnrollments(Collection $terms, array $options): array
    {
        $byTerm = [];
        $total = 0;
        foreach ($terms as $term) {
            $classes = $this->loadClasses($term, $options);
            $total += $classes->count();
            $byTerm[$term->id] = $classes;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->setFormat("Buscando estmtr no Replicado: [%bar%] %percent:3s%%");
        $bar->start();

        foreach ($byTerm as $classes) {
            foreach ($classes as $schoolClass) {
                try {
                    $schoolClass->calcEstimadedEnrollment();
                } catch (\Exception $e) {
                    $this->error("\nFalha ao atualizar turma {$schoolClass->codtur} ({$schoolClass->coddis}): " . $e->getMessage());
                }
                $bar->advance();
            }
        }

        $bar->finish();
        $this->newLine(2);

        return $byTerm;
    }

    private function schoolClassKey(SchoolClass $class): string
    {
        if ($class->tiptur === 'Pós Graduação') {
            return $class->coddis;
        }
        return $class->coddis . '-' . substr($class->codtur, -2);
    }

    private function buildComparablePairs(Collection $currentClasses, array $baselineClassesByTerm, Collection $baselineTerms, float $minEnrollment): Collection
    {
        $baselineIndexed = [];
        foreach ($baselineClassesByTerm as $termId => $classes) {
            foreach ($classes as $class) {
                $key = $this->schoolClassKey($class);
                $baselineIndexed[$key][$termId] = $class;
            }
        }

        $pairs = [];
        foreach ($currentClasses as $current) {
            $key = $this->schoolClassKey($current);
            if (!isset($baselineIndexed[$key])) {
                continue;
            }
            $matches = $baselineIndexed[$key];

            $baselineValues = [];
            $baselineDetail = [];
            foreach ($baselineTerms as $bt) {
                if (isset($matches[$bt->id])) {
                    $base = $matches[$bt->id];
                    if (!is_null($base->estmtr)) {
                        $baselineValues[] = (float) $base->estmtr;
                    }
                    $baselineDetail[] = [
                        'year' => $bt->year,
                        'period' => $bt->period,
                        'enrollment' => is_null($base->estmtr) ? null : (float) $base->estmtr,
                    ];
                }
            }

            if (empty($baselineValues) || is_null($current->estmtr)) {
                continue;
            }

            $baselineAvg = array_sum($baselineValues) / count($baselineValues);
            if ($baselineAvg < $minEnrollment) {
                continue;
            }

            $currentEnrollment = (float) $current->estmtr;
            if ($baselineAvg > 0) {
                $dropPct = ($baselineAvg - $currentEnrollment) / $baselineAvg * 100.0;
            } else {
                $dropPct = 0.0;
            }
            $deficit = $baselineAvg - $currentEnrollment;

            $pairs[] = [
                'coddis' => $current->coddis,
                'codtur' => $current->codtur,
                'suffix' => $current->tiptur === 'Pós Graduação' ? '' : substr($current->codtur, -2),
                'nomdis' => $current->nomdis,
                'tipo' => $current->tiptur,
                'externa' => (bool) $current->externa,
                'current_enrollment' => $currentEnrollment,
                'baseline_avg' => round($baselineAvg, 2),
                'baseline_years' => $baselineDetail,
                'drop_pct' => round($dropPct, 2),
                'deficit' => round($deficit, 2),
            ];
        }

        return collect($pairs);
    }

    private function buildUnmatched(Collection $currentClasses, array $baselineClassesByTerm, float $minEnrollment): Collection
    {
        $hasBaseline = [];
        foreach ($baselineClassesByTerm as $classes) {
            foreach ($classes as $class) {
                $hasBaseline[$this->schoolClassKey($class)] = true;
            }
        }

        $unmatched = [];
        foreach ($currentClasses as $current) {
            $key = $this->schoolClassKey($current);
            if (isset($hasBaseline[$key])) {
                continue;
            }
            $unmatched[] = [
                'coddis' => $current->coddis,
                'codtur' => $current->codtur,
                'suffix' => $current->tiptur === 'Pós Graduação' ? '' : substr($current->codtur, -2),
                'nomdis' => $current->nomdis,
                'tipo' => $current->tiptur,
                'current_enrollment' => is_null($current->estmtr) ? null : (float) $current->estmtr,
            ];
        }
        return collect($unmatched)->sortByDesc('current_enrollment')->values();
    }

    private function buildSkipped(Collection $currentClasses, array $baselineClassesByTerm, Collection $baselineTerms, float $minEnrollment): Collection
    {
        $baselineIndexed = [];
        foreach ($baselineClassesByTerm as $termId => $classes) {
            foreach ($classes as $class) {
                $key = $this->schoolClassKey($class);
                $baselineIndexed[$key][$termId] = $class;
            }
        }

        $skipped = [];
        foreach ($currentClasses as $current) {
            $key = $this->schoolClassKey($current);
            if (!isset($baselineIndexed[$key])) {
                continue;
            }

            $matches = $baselineIndexed[$key];
            $baselineValues = [];
            $baselineDetail = [];
            foreach ($baselineTerms as $bt) {
                if (isset($matches[$bt->id])) {
                    $base = $matches[$bt->id];
                    if (!is_null($base->estmtr)) {
                        $baselineValues[] = (float) $base->estmtr;
                    }
                    $baselineDetail[] = [
                        'year' => $bt->year,
                        'enrollment' => is_null($base->estmtr) ? null : (float) $base->estmtr,
                    ];
                }
            }

            $currentEnrollment = is_null($current->estmtr) ? null : (float) $current->estmtr;

            if (is_null($currentEnrollment)) {
                $reason = 'estmtr atual nulo';
            } elseif (empty($baselineValues)) {
                $reason = 'baseline sem estmtr (todos nulos)';
            } else {
                $baselineAvg = array_sum($baselineValues) / count($baselineValues);
                if ($baselineAvg < $minEnrollment) {
                    $reason = "baseline media {$baselineAvg} < {$minEnrollment}";
                } else {
                    continue;
                }
            }

            $skipped[] = [
                'coddis' => $current->coddis,
                'codtur' => $current->codtur,
                'suffix' => $current->tiptur === 'Pós Graduação' ? '' : substr($current->codtur, -2),
                'nomdis' => $current->nomdis,
                'tipo' => $current->tiptur,
                'current_enrollment' => $currentEnrollment,
                'baseline_years' => $baselineDetail,
                'reason' => $reason,
            ];
        }

        return collect($skipped)
            ->sortByDesc('current_enrollment')
            ->values();
    }

    private function computeSummary(Collection $pairs, array $options): array
    {
        $n = $pairs->count();
        if ($n === 0) {
            return [
                'comparable_pairs' => 0,
                'undersized_count' => 0,
                'undersized_pct' => 0.0,
                'total_deficit' => 0.0,
                'median_drop_pct' => 0.0,
                'mean_drop_pct' => 0.0,
                'mean_abs_deficit' => 0.0,
            ];
        }

        $undersized = $pairs->filter(fn($p) => $p['drop_pct'] >= $options['min_drop']);
        $totalDeficit = $pairs->sum(fn($p) => $p['deficit']);
        $medianDrop = $this->median($pairs->pluck('drop_pct')->all());
        $meanDrop = $pairs->avg('drop_pct');
        $meanAbsDeficit = $pairs->avg(fn($p) => abs($p['deficit']));

        return [
            'comparable_pairs' => $n,
            'undersized_count' => $undersized->count(),
            'undersized_pct' => round($n > 0 ? ($undersized->count() / $n) * 100.0 : 0.0, 2),
            'total_deficit' => round($totalDeficit, 2),
            'median_drop_pct' => round($medianDrop, 2),
            'mean_drop_pct' => round($meanDrop, 2),
            'mean_abs_deficit' => round($meanAbsDeficit, 2),
        ];
    }

    private function buildPayload(SchoolTerm $currentTerm, Collection $baselineTerms, array $options, array $summary, Collection $ranking, Collection $pairs, Collection $unmatched, Collection $skipped, array $totals): array
    {
        return [
            'current_term' => ['year' => $currentTerm->year, 'period' => $currentTerm->period],
            'baseline_terms' => $baselineTerms->map(fn($t) => ['year' => $t->year, 'period' => $t->period])->values()->all(),
            'options' => $options,
            'totals' => $totals,
            'summary' => $summary,
            'ranking_total' => $pairs->count(),
            'ranking' => $ranking->toArray(),
            'unmatched' => $unmatched->toArray(),
            'skipped' => $skipped->toArray(),
        ];
    }

    private function buildTotals(SchoolTerm $currentTerm, Collection $currentClasses, Collection $baselineTerms, array $baselineClassesByTerm, Collection $pairs, Collection $unmatched, Collection $skipped, array $options): array
    {
        $currentTotal = $currentClasses->count();
        $matched = $pairs->count();
        $unmatchedCount = $unmatched->count();
        $excludedCount = $skipped->count();
        $matchedPct = $currentTotal > 0 ? round(($matched / $currentTotal) * 100.0, 2) : 0.0;

        $baseline = [];
        foreach ($baselineTerms as $bt) {
            $count = isset($baselineClassesByTerm[$bt->id]) ? $baselineClassesByTerm[$bt->id]->count() : 0;
            $baseline[] = [
                'year' => $bt->year,
                'period' => $bt->period,
                'total_classes' => $count,
            ];
        }

        // Variação % do total atual em relação à média do total dos anos-base
        $baselineAvg = collect($baseline)->avg('total_classes') ?? 0;
        $totalVarPct = $baselineAvg > 0 ? round((($currentTotal - $baselineAvg) / $baselineAvg) * 100.0, 2) : null;

        return [
            'filter_scope' => [
                'tipo' => $options['tipo'] ?? 'todos',
                'include_externa' => $options['include_externa'],
            ],
            'current' => [
                'year' => $currentTerm->year,
                'period' => $currentTerm->period,
                'total_classes' => $currentTotal,
                'matched' => $matched,
                'unmatched' => $unmatchedCount,
                'excluded_by_filter' => $excludedCount,
                'matched_pct' => $matchedPct,
            ],
            'baseline' => $baseline,
            'baseline_avg_total_classes' => round($baselineAvg, 2),
            'total_classes_var_pct' => $totalVarPct,
        ];
    }

    private function renderFriendly(array $payload): string
    {
        $out = '';
        $cur = $payload['current_term'];
        $baseline = $payload['baseline_terms'];
        $baselineLabel = collect($baseline)->map(fn($t) => "{$t['period']} de {$t['year']}")->implode(', ');
        $title = "Comparação de Inscritos: {$cur['period']} de {$cur['year']} vs {$baselineLabel}";

        $width = mb_strlen($title) + 4;
        $line = str_repeat('=', $width);
        $out .= $line . "\n";
        $out .= "  " . $title . "\n";
        $out .= $line . "\n\n";

        $s = $payload['summary'];
        $out .= "[ RESUMO ESTATÍSTICO ]\n";
        $out .= "Pares comparáveis: {$s['comparable_pairs']}\n";
        $out .= "Turmas subdimensionadas (queda >= {$payload['options']['min_drop']}%): {$s['undersized_count']} ({$s['undersized_pct']}%)\n";
        $out .= "Deficit total estimado (soma baseline - atual): {$s['total_deficit']}\n";
        $out .= "Mediana da queda (%): {$s['median_drop_pct']}\n";
        $out .= "Média da queda (%): {$s['mean_drop_pct']}\n";
        $out .= "Média do deficit absoluto: {$s['mean_abs_deficit']}\n\n";

        // Totais de turmas por semestre
        $t = $payload['totals'];
        $scope = $t['filter_scope']['include_externa'] ? 'internas + externas' : 'apenas internas';
        $tipoScope = $t['filter_scope']['tipo'] === 'todos' ? 'todos os tipos' : $t['filter_scope']['tipo'];
        $out .= "[ TOTAL DE TURMAS POR SEMESTRE (escopo: {$scope} | {$tipoScope}) ]\n";
        $headers = ['Semestre', 'Total de Turmas', 'Com Par', 'Sem Par (s/ baseline)', 'Excl. por filtro', '% Com Par'];
        $rows = [];
        foreach ($t['baseline'] as $b) {
            $rows[] = [
                "{$b['period']} de {$b['year']}",
                $b['total_classes'],
                '-',
                '-',
                '-',
                '-',
            ];
        }
        $c = $t['current'];
        $rows[] = [
            "{$c['period']} de {$c['year']} (atual)",
            $c['total_classes'],
            $c['matched'],
            $c['unmatched'],
            $c['excluded_by_filter'],
            $c['matched_pct'] . '%',
        ];
        $out .= $this->formatTableForFile($headers, $rows) . "\n";
        if ($c['excluded_by_filter'] > 0) {
            $out .= "  (Excl. por filtro = tiveram baseline mas foram descartadas por estmtr nulo ou baseline < --min-enrollment={$payload['options']['min_enrollment']})\n";
        }
        if ($t['total_classes_var_pct'] !== null) {
            $signVar = $t['total_classes_var_pct'] >= 0 ? '+' : '';
            $out .= sprintf("Variação total atual vs média baseline: %s%s%%\n", $signVar, $t['total_classes_var_pct']);
        }
        $out .= "Média do total de turmas nos anos-base: {$t['baseline_avg_total_classes']}\n\n";
        if ($c['matched_pct'] < 60) {
            $out .= "(!) Apenas {$c['matched']} de {$c['total_classes']} turmas atuais ({$c['matched_pct']}%) entraram no comparativo. {$c['unmatched']} não têm equivalente no(s) ano(s) anterior(es) (turmas novas) e {$c['excluded_by_filter']} foram excluídas pelo filtro (ver seção \"TURMAS EXCLUÍDAS\" abaixo). Verifique abaixo o motivo de cada exclusão.\n\n";
        }

        // Ranking
        $ranking = $payload['ranking'];
        $topVal = $payload['options']['top'];
        if ($topVal === 0) {
            // Ranking explicitamente suprimido pelo usuário
        } elseif (empty($ranking) && $payload['ranking_total'] > 0) {
            $out .= "[ RANKING DOS PIORES CASOS (0 exibidos de {$payload['ranking_total']}) ]\n";
        } elseif (empty($ranking)) {
            $out .= "Nenhum par comparável para o ranking.\n";
        } else {
            $showing = $payload['options']['top'] < 0 ? 'TODOS' : $payload['options']['top'];
            $out .= "[ RANKING DOS PIORES CASOS (mostrando {$showing} de {$payload['ranking_total']}) ]\n";
            $headers = ['Disciplina', 'Turma', 'Tipo', 'Insc. Atual', 'Baseline (média)', 'Anos base', 'Queda %', 'Deficit'];
            $rows = [];
            foreach ($ranking as $item) {
                $yearsBase = collect($item['baseline_years'])->map(fn($y) => (string)$y['year'] . ':' . ($y['enrollment'] ?? '-'))->implode(' | ');
                $drop = $item['drop_pct'] >= $payload['options']['min_drop'] ? "!" . $item['drop_pct'] : (string)$item['drop_pct'];
                $rows[] = [
                    $item['coddis'],
                    $item['suffix'] !== '' ? $item['suffix'] : $item['codtur'],
                    $item['tipo'],
                    $item['current_enrollment'],
                    $item['baseline_avg'],
                    $yearsBase,
                    $drop,
                    $item['deficit'],
                ];
            }
            $out .= $this->formatTableForFile($headers, $rows) . "\n";
        }

        // Unmatched
        $unmatched = $payload['unmatched'];
        $out .= "[ TURMAS SEM PAR NO(S) ANO(S) ANTERIOR(ES): " . count($unmatched) . " ]\n";
        if (!empty($unmatched)) {
            $headers = ['Disciplina', 'Turma', 'Tipo', 'Insc. Atual'];
            $rows = [];
            foreach ($unmatched as $item) {
                $rows[] = [
                    $item['coddis'],
                    $item['suffix'] !== '' ? $item['suffix'] : $item['codtur'],
                    $item['tipo'],
                    $item['current_enrollment'] ?? '-',
                ];
            }
            $out .= $this->formatTableForFile($headers, $rows) . "\n";
        }

        // Skipped: tinham equivalente mas foram excluídas do comparativo
        $skipped = $payload['skipped'];
        if (!$payload['options']['omit_skipped']) {
            $out .= "\n[ TURMAS EXCLUÍDAS DO COMPARATIVO (tinham par): " . count($skipped) . " ]\n";
            if (!empty($skipped)) {
                $headers = ['Disciplina', 'Turma', 'Tipo', 'Insc. Atual', 'Baseline (anos)', 'Motivo'];
                $rows = [];
                foreach ($skipped as $item) {
                    $yearsBase = collect($item['baseline_years'])->map(fn($y) => (string)$y['year'] . ':' . ($y['enrollment'] ?? '-'))->implode(' | ');
                    $rows[] = [
                        $item['coddis'],
                        $item['suffix'] !== '' ? $item['suffix'] : $item['codtur'],
                        $item['tipo'],
                        $item['current_enrollment'] ?? '-',
                        $yearsBase,
                        $item['reason'],
                    ];
                }
                $out .= $this->formatTableForFile($headers, $rows) . "\n";
            }
        } else {
            $out .= "\n(Turmas excluídas omitidas via --omit-skipped: " . count($skipped) . ")\n";
        }

        $out .= str_repeat('-', mb_strlen($title) + 4) . "\n";

        return $out;
    }

    private function renderMarkdown(array $payload): string
    {
        $cur = $payload['current_term'];
        $baseline = $payload['baseline_terms'];
        $baselineLabel = collect($baseline)->map(fn($t) => "{$t['period']} de {$t['year']}")->implode(', ');

        $out = "## Comparação de Inscritos: {$cur['period']} de {$cur['year']} vs {$baselineLabel}\n\n";

        // Resumo estatístico
        $s = $payload['summary'];

        $out .= "### Resumo estatístico\n\n";
        $out .= "| Métrica | Valor |\n|:---|---:|\n";
        $out .= "| Pares comparáveis | {$s['comparable_pairs']} |\n";
        $out .= "| Turmas subdimensionadas (queda >= {$payload['options']['min_drop']}%) | {$s['undersized_count']} ({$s['undersized_pct']}%) |\n";
        $out .= "| Deficit total estimado (baseline - atual) | {$s['total_deficit']} |\n";
        $out .= "| Mediana da queda (%) | {$s['median_drop_pct']} |\n";
        $out .= "| Média da queda (%) | {$s['mean_drop_pct']} |\n";
        $out .= "| Média do deficit absoluto | {$s['mean_abs_deficit']} |\n\n";

        // Totais de turmas por semestre
        $t = $payload['totals'];
        $scope = $t['filter_scope']['include_externa'] ? 'internas + externas' : 'apenas internas';
        $tipoScope = $t['filter_scope']['tipo'] === 'todos' ? 'todos os tipos' : $t['filter_scope']['tipo'];

        $out .= "### Total de turmas por semestre\n\n";
        $out .= "_Escopo: {$scope} | {$tipoScope}_\n\n";
        $out .= "| Semestre | Total de Turmas | Com Par | Sem Par (s/ baseline) | Excl. por filtro | % Com Par |\n";
        $out .= "|:--|--:|--:|--:|--:|--:|\n";
        foreach ($t['baseline'] as $b) {
            $out .= "| {$b['period']} de {$b['year']} | {$b['total_classes']} | - | - | - | - |\n";
        }
        $c = $t['current'];
        $out .= "| **{$c['period']} de {$c['year']} (atual)** | **{$c['total_classes']}** | **{$c['matched']}** | **{$c['unmatched']}** | **{$c['excluded_by_filter']}** | **{$c['matched_pct']}%** |\n\n";
        if ($t['total_classes_var_pct'] !== null) {
            $signVar = $t['total_classes_var_pct'] >= 0 ? '+' : '';
            $out .= sprintf("**Variação total atual vs média baseline:** %s%s%%  \n", $signVar, $t['total_classes_var_pct']);
        }
        $out .= "**Média do total de turmas nos anos-base:** {$t['baseline_avg_total_classes']}\n\n";
        if ($c['matched_pct'] < 60) {
            $out .= "> ⚠️ Apenas {$c['matched']} de {$c['total_classes']} turmas atuais ({$c['matched_pct']}%) entraram no comparativo. {$c['unmatched']} não têm equivalente no(s) ano(s) anterior(es) (turmas novas) e {$c['excluded_by_filter']} foram excluídas pelo filtro (ver seção abaixo).\n\n";
        }

        // Ranking
        $ranking = $payload['ranking'];
        $topVal = $payload['options']['top'];
        if ($topVal === 0) {
            // Ranking explicitamente suprimido
        } elseif ($payload['ranking_total'] > 0) {
            $showing = $topVal < 0 ? 'TODOS' : $topVal;
            $out .= "### Ranking dos piores casos (mostrando {$showing} de {$payload['ranking_total']})\n\n";
            $out .= "| Disciplina | Turma | Tipo | Insc. Atual | Baseline (média) | Anos base | Queda % | Deficit |\n";
            $out .= "|:--|:--|:--|--:|--:|:--|--:|--:|\n";
            foreach ($ranking as $item) {
                $yearsBase = collect($item['baseline_years'])->map(fn($y) => (string)$y['year'] . ':' . ($y['enrollment'] ?? '-'))->implode(' / ');
                $drop = $item['drop_pct'] >= $payload['options']['min_drop'] ? "**{$item['drop_pct']}** ⚠️" : (string)$item['drop_pct'];
                $turma = $item['suffix'] !== '' ? $item['suffix'] : $item['codtur'];
                $out .= "| {$item['coddis']} | {$turma} | {$item['tipo']} | {$item['current_enrollment']} | {$item['baseline_avg']} | {$yearsBase} | {$drop} | {$item['deficit']} |\n";
            }
            $out .= "\n";
        }

        // Turmas sem par
        $unmatched = $payload['unmatched'];
        $out .= "### Turmas sem par no(s) ano(s) anterior(es): " . count($unmatched) . "\n\n";
        if (!empty($unmatched)) {
            $out .= "| Disciplina | Turma | Tipo | Insc. Atual |\n|:--|:--|:--|--:|\n";
            foreach ($unmatched as $item) {
                $turma = $item['suffix'] !== '' ? $item['suffix'] : $item['codtur'];
                $out .= "| {$item['coddis']} | {$turma} | {$item['tipo']} | " . ($item['current_enrollment'] ?? '-') . " |\n";
            }
            $out .= "\n";
        }

        // Turmas excluídas (skipped)
        $skipped = $payload['skipped'];
        if (!$payload['options']['omit_skipped']) {
            $out .= "### Turmas excluídas do comparativo (tinham par): " . count($skipped) . "\n\n";
            $out .= "_Motivo: estmtr nulo ou baseline média < --min-enrollment={$payload['options']['min_enrollment']}_\n\n";
            if (!empty($skipped)) {
                $out .= "| Disciplina | Turma | Tipo | Insc. Atual | Baseline (anos) | Motivo |\n";
                $out .= "|:--|:--|:--|--:|:--|:--|\n";
                foreach ($skipped as $item) {
                    $yearsBase = collect($item['baseline_years'])->map(fn($y) => (string)$y['year'] . ':' . ($y['enrollment'] ?? '-'))->implode(' / ');
                    $turma = $item['suffix'] !== '' ? $item['suffix'] : $item['codtur'];
                    $out .= "| {$item['coddis']} | {$turma} | {$item['tipo']} | " . ($item['current_enrollment'] ?? '-') . " | {$yearsBase} | {$item['reason']} |\n";
                }
                $out .= "\n";
            }
        } else {
            $out .= "_Turmas excluídas omitidas via --omit-skipped: " . count($skipped) . "_\n\n";
        }

        return $out;
    }
    private function formatTableForFile(array $headers, array $rows): string
    {
        if (empty($rows)) {
            return '(sem linhas)';
        }
        $widths = [];
        foreach ($headers as $i => $h) {
            $widths[$i] = mb_strlen($h);
        }
        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $len = mb_strlen((string)$cell);
                if ($len > ($widths[$i] ?? 0)) {
                    $widths[$i] = $len;
                }
            }
        }

        $table = '|';
        foreach ($headers as $i => $h) {
            $table .= ' ' . $this->mbPad($h, $widths[$i]) . ' |';
        }
        $table .= "\n|";
        foreach ($headers as $i => $h) {
            $table .= ':' . str_repeat('-', $widths[$i]) . ':|';
        }
        $table .= "\n";
        foreach ($rows as $row) {
            $table .= '|';
            foreach ($row as $i => $cell) {
                $table .= ' ' . $this->mbPad((string)$cell, $widths[$i]) . ' |';
            }
            $table .= "\n";
        }
        return $table;
    }

    private function median(array $values): float
    {
        $values = array_values($values);
        $n = count($values);
        if ($n === 0) {
            return 0.0;
        }
        sort($values);
        if ($n % 2 === 1) {
            return (float)$values[(int)floor($n / 2)];
        }
        return ((float)$values[$n / 2 - 1] + (float)$values[$n / 2]) / 2.0;
    }

    private function mbPad(string $string, int $length): string
    {
        $diff = $length - mb_strlen($string);
        if ($diff <= 0) {
            return $string;
        }
        return $string . str_repeat(' ', $diff);
    }
}