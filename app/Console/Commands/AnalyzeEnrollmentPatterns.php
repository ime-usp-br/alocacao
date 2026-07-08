<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use App\Models\SchoolTerm;
use App\Models\SchoolClass;
use App\Models\CourseInformation;

class AnalyzeEnrollmentPatterns extends Command
{
    protected $signature = 'schoolclass:analyze-enrollment-patterns
                            {--tipo= : Filtra turmas pelo tipo (ex: "Graduação")}
                            {--years=4 : Número de anos anteriores como baseline}
                            {--min-drop=10 : Variação % mínima (módulo) para sub/superdimensionada}
                            {--min-enrollment=0 : Ignora pares cuja baseline média seja menor que este valor}
                            {--year= : Ano do semestre atual}
                            {--period= : Período do semestre atual}
                            {--skip-update : Não busca estmtr no Replicado antes de analisar; usa os valores já gravados no BD (mais rápido)}
                            {--json : Devolve a saída em JSON}
                            {--markdown : Devolve a saída em Markdown}
                            {--output= : Salva o relatório (amigável ou markdown) em um arquivo de texto}';

    protected $description = 'Analisa padrões (curso, tipobg, semestre ideal) entre turmas sub e superdimensionadas do comparativo de inscritos.';

    private const TIPOBG_LABELS = [
        'O' => 'Obrigatória',
        'C' => 'Complementar',
        'L' => 'Livre',
        'E' => 'Eletiva',
    ];

    private array $suffixToCourse;

    public function handle()
    {
        $currentTerm = $this->resolveCurrentTerm();
        if (!$currentTerm) {
            $this->error('Nenhum período letivo atual encontrado.');
            return Command::FAILURE;
        }

        $this->suffixToCourse = CourseInformation::$codtur_by_course;

        $years = max(1, (int) $this->option('years'));
        $baselineTerms = $this->resolveBaselineTerms($currentTerm, $years);
        if ($baselineTerms->isEmpty()) {
            $this->error("Nenhum período base encontrado.");
            return Command::FAILURE;
        }

        $options = [
            'tipo' => $this->option('tipo'),
            'min_drop' => (float) $this->option('min-drop'),
            'min_enrollment' => (float) $this->option('min-enrollment'),
            'years' => $years,
        ];

        if ($this->option('json') && $this->option('markdown')) {
            $this->error('As opções --json e --markdown são mutuamente exclusivas.');
            return Command::FAILURE;
        }
        if ($this->option('json') && $this->option('output')) {
            $this->error('As opções --json e --output são mutuamente exclusivas.');
            return Command::FAILURE;
        }

        $skipUpdate = (bool) $this->option('skip-update');

        $currentClasses = $this->loadClasses($currentTerm, $options);
        $baselineClassesByTerm = [];
        foreach ($baselineTerms as $bt) {
            $baselineClassesByTerm[$bt->id] = $this->loadClasses($bt, $options);
        }

        if (!$skipUpdate) {
            $this->info('Buscando inscritos (estmtr) no Replicado para comparação (sem gravar no BD)...');
            $total = $currentClasses->count() + collect($baselineClassesByTerm)->sum(fn($c) => $c->count());
            $bar = $this->output->createProgressBar($total);
            $bar->setFormat("Buscando estmtr no Replicado: [%bar%] %percent:3s%%");
            $bar->start();

            foreach (collect([$currentTerm])->merge($baselineTerms) as $term) {
                $classes = ($term->id === $currentTerm->id)
                    ? $currentClasses
                    : ($baselineClassesByTerm[$term->id] ?? collect());
                foreach ($classes as $sc) {
                    try {
                        $sc->calcEstimadedEnrollment();
                    } catch (\Exception $e) {
                        $this->error("\nFalha ao atualizar turma {$sc->codtur} ({$sc->coddis}): " . $e->getMessage());
                    }
                    $bar->advance();
                }
            }
            $bar->finish();
            $this->newLine(2);
        }

        // Pré-carrega courseinformations das turmas atuais
        $currentClasses->load('courseinformations');

        $pairs = $this->buildComparablePairs($currentClasses, $baselineClassesByTerm, $baselineTerms, $options['min_enrollment']);

        $undersized = $pairs->filter(fn($p) => $p['variation_pct'] <= -$options['min_drop'])->values();
        $oversized = $pairs->filter(fn($p) => $p['variation_pct'] >= $options['min_drop'])->values();
        $normal = $pairs->filter(fn($p) => abs($p['variation_pct']) < $options['min_drop'])->values();

        $payload = $this->buildPayload(
            $currentTerm,
            $baselineTerms,
            $options,
            $pairs,
            $undersized,
            $oversized,
            $normal,
            $currentClasses,
        );

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }

        $report = $this->option('markdown')
            ? $this->renderMarkdown($payload)
            : $this->renderFriendly($payload);

        if ($output = $this->option('output')) {
            File::put($output, $report);
            $this->info("Relatório salvo em: {$output}");
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
        return SchoolTerm::getLatest();
    }

    private function resolveBaselineTerms(SchoolTerm $currentTerm, int $years): Collection
    {
        $terms = collect();
        for ($i = 1; $i <= $years; $i++) {
            $t = SchoolTerm::where('year', $currentTerm->year - $i)->where('period', $currentTerm->period)->first();
            if ($t) $terms->push($t);
        }
        return $terms;
    }

    private function loadClasses(SchoolTerm $term, array $options): Collection
    {
        $query = SchoolClass::whereBelongsTo($term);
        if ($options['tipo']) $query->where('tiptur', $options['tipo']);
        $query->where('externa', false);
        return $query->get();
    }

    private function schoolClassKey(SchoolClass $class): string
    {
        if ($class->tiptur === 'Pós Graduação') return $class->coddis;
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
            if (!isset($baselineIndexed[$key])) continue;
            $matches = $baselineIndexed[$key];

            $baselineValues = [];
            foreach ($baselineTerms as $bt) {
                if (isset($matches[$bt->id]) && !is_null($matches[$bt->id]->estmtr)) {
                    $baselineValues[] = (float) $matches[$bt->id]->estmtr;
                }
            }

            if (empty($baselineValues) || is_null($current->estmtr)) continue;

            $baselineAvg = array_sum($baselineValues) / count($baselineValues);
            if ($baselineAvg < $minEnrollment) continue;

            $baselineAvg = round($baselineAvg);
            $currentEnrollment = (float) $current->estmtr;
            $variationPct = $baselineAvg > 0 ? ($currentEnrollment - $baselineAvg) / $baselineAvg * 100.0 : 0.0;

            $pairs[] = [
                'coddis' => $current->coddis,
                'codtur' => $current->codtur,
                'suffix' => substr($current->codtur, -2),
                'nomdis' => $current->nomdis,
                'schoolclass_id' => $current->id,
                'current_enrollment' => $currentEnrollment,
                'baseline_avg' => $baselineAvg,
                'variation_pct' => round($variationPct, 2),
                'deficit' => $baselineAvg - $currentEnrollment,
            ];
        }

        return collect($pairs);
    }

    private function courseLabelForSuffix(string $suffix): string
    {
        if (isset($this->suffixToCourse[$suffix])) {
            $c = $this->suffixToCourse[$suffix];
            $hab = $c['perhab'] ?? '';
            $grupo = $c['grupo'] ?? '';
            $extra = trim("{$hab} {$grupo}");
            return $c['nomcur'] . ($extra ? " ({$extra})" : '');
        }
        if ($suffix === '41' || $suffix === '51') return 'Oferecimento institucional (41/51)';
        return "Sufixo {$suffix} (não mapeado)";
    }

    private function courseInfoForClass(int $schoolclassId, Collection $currentClasses): array
    {
        $sc = $currentClasses->firstWhere('id', $schoolclassId);
        if (!$sc) return [];

        $suffix = substr($sc->codtur, -2);
        $primaryCodcur = $this->suffixToCourse[$suffix]['codcur'] ?? null;
        $primaryPerhab = $this->suffixToCourse[$suffix]['perhab'] ?? null;

        $infos = $sc->courseinformations;
        if ($infos->isEmpty()) return [];

        $primary = $infos->firstWhere('codcur', $primaryCodcur);
        if ($primary && $primaryPerhab && $primary->perhab !== $primaryPerhab) {
            $alt = $infos->firstWhere('codcur', $primaryCodcur);
            $primary = $alt ?: $primary;
        }

        return $infos->map(fn($ci) => [
            'nomcur' => $ci->nomcur,
            'codcur' => $ci->codcur,
            'perhab' => $ci->perhab,
            'tipobg' => $ci->tipobg,
            'numsemidl' => $ci->numsemidl,
            'is_primary' => $primary ? ($ci->id === $primary->id) : false,
        ])->toArray();
    }

    private function primaryInfoForPair(array $pair, Collection $currentClasses): array
    {
        $infos = $this->courseInfoForClass($pair['schoolclass_id'], $currentClasses);
        if (empty($infos)) {
            return [
                'course_label' => $this->courseLabelForSuffix($pair['suffix']),
                'nomcur' => null,
                'tipobg' => null,
                'tipobg_label' => '?',
                'numsemidl' => '?',
            ];
        }
        $primary = collect($infos)->firstWhere('is_primary', true) ?? $infos[0];
        return [
            'course_label' => $primary['nomcur'] . " ({$pair['suffix']})",
            'nomcur' => $primary['nomcur'],
            'tipobg' => $primary['tipobg'] ?? null,
            'tipobg_label' => self::TIPOBG_LABELS[$primary['tipobg'] ?? '?'] ?? '?',
            'numsemidl' => $primary['numsemidl'] ?? '?',
        ];
    }

    private function analyzeGroup(Collection $group, Collection $currentClasses): array
    {
        if ($group->isEmpty()) {
            return [
                'count' => 0,
                'by_course' => [],
                'by_tipobg' => [],
                'by_semester' => [],
                'course_x_tipobg' => [],
                'details' => [],
            ];
        }

        $bySuffix = [];
        $byTipobg = [];
        $bySemester = [];
        $byCourseTipobg = [];
        $details = [];

        foreach ($group as $p) {
            $suffix = $p['suffix'];
            $courseLabel = $this->courseLabelForSuffix($suffix);
            $bySuffix[$courseLabel] = ($bySuffix[$courseLabel] ?? 0) + 1;

            $info = $this->primaryInfoForPair($p, $currentClasses);
            $tipobgLabel = $info['tipobg_label'];
            $byTipobg[$tipobgLabel] = ($byTipobg[$tipobgLabel] ?? 0) + 1;
            $bySemester[$info['numsemidl']] = ($bySemester[$info['numsemidl']] ?? 0) + 1;
            $byCourseTipobg[$courseLabel][$tipobgLabel] = ($byCourseTipobg[$courseLabel][$tipobgLabel] ?? 0) + 1;

            $details[] = [
                'coddis' => $p['coddis'],
                'suffix' => $p['suffix'],
                'course' => $info['course_label'],
                'tipobg' => $info['tipobg_label'],
                'semester' => $info['numsemidl'],
                'current_enrollment' => $p['current_enrollment'],
                'baseline_avg' => $p['baseline_avg'],
                'variation_pct' => $p['variation_pct'],
                'deficit' => $p['deficit'],
            ];
        }

        $total = $group->count();

        $bySuffixPct = [];
        arsort($bySuffix);
        foreach ($bySuffix as $course => $count) {
            $bySuffixPct[] = ['course' => $course, 'count' => $count, 'pct' => round($count / $total * 100, 1)];
        }

        $byTipobgPct = [];
        arsort($byTipobg);
        foreach ($byTipobg as $tipo => $count) {
            $byTipobgPct[] = ['tipobg' => $tipo, 'count' => $count, 'pct' => round($count / $total * 100, 1)];
        }

        $bySemesterPct = [];
        ksort($bySemester, SORT_NATURAL);
        foreach ($bySemester as $sem => $count) {
            $bySemesterPct[] = ['semester' => (string) $sem, 'count' => $count, 'pct' => round($count / $total * 100, 1)];
        }

        $courseXtipobg = [];
        foreach ($byCourseTipobg as $course => $tipos) {
            arsort($tipos);
            $courseXtipobg[] = [
                'course' => $course,
                'tipos' => collect($tipos)->map(fn($c, $t) => ['tipobg' => $t, 'count' => $c])->values()->all(),
            ];
        }

        usort($details, fn($a, $b) => $b['deficit'] <=> $a['deficit']);

        return [
            'count' => $total,
            'by_course' => $bySuffixPct,
            'by_tipobg' => $byTipobgPct,
            'by_semester' => $bySemesterPct,
            'course_x_tipobg' => $courseXtipobg,
            'details' => $details,
        ];
    }

    private function buildCrossTable(Collection $undersized, Collection $oversized, Collection $normal, Collection $currentClasses): array
    {
        $build = function (Collection $group) use ($currentClasses) {
            $counts = [];
            foreach ($group as $p) {
                $info = $this->primaryInfoForPair($p, $currentClasses);
                $key = "{$info['course_label']} | {$info['tipobg_label']}";
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
            return $counts;
        };

        $sub = $build($undersized);
        $super = $build($oversized);
        $norm = $build($normal);

        $keys = collect(array_keys($sub + $super + $norm))->sort()->values();

        $rows = [];
        foreach ($keys as $k) {
            $rows[] = [
                'course_tipobg' => $k,
                'sub' => $sub[$k] ?? 0,
                'super' => $super[$k] ?? 0,
                'normal' => $norm[$k] ?? 0,
            ];
        }
        return $rows;
    }

    private function buildPayload(SchoolTerm $currentTerm, Collection $baselineTerms, array $options, Collection $pairs, Collection $undersized, Collection $oversized, Collection $normal, Collection $currentClasses): array
    {
        return [
            'current_term' => ['year' => $currentTerm->year, 'period' => $currentTerm->period],
            'baseline_terms' => $baselineTerms->map(fn($t) => ['year' => $t->year, 'period' => $t->period])->values()->all(),
            'options' => $options,
            'summary' => [
                'comparable_pairs' => $pairs->count(),
                'undersized_count' => $undersized->count(),
                'oversized_count' => $oversized->count(),
                'normal_count' => $normal->count(),
            ],
            'undersized' => $this->analyzeGroup($undersized, $currentClasses),
            'oversized' => $this->analyzeGroup($oversized, $currentClasses),
            'cross_course_tipobg' => $this->buildCrossTable($undersized, $oversized, $normal, $currentClasses),
        ];
    }

    private function renderFriendly(array $payload): string
    {
        $cur = $payload['current_term'];
        $baseline = $payload['baseline_terms'];
        $baselineLabel = collect($baseline)->map(fn($t) => "{$t['period']} de {$t['year']}")->implode(', ');
        $title = "Análise de Padrões: {$cur['period']} de {$cur['year']} vs {$baselineLabel}";

        $out = '';
        $out .= "\n" . $title . "\n";
        $out .= str_repeat('=', 100) . "\n";
        $s = $payload['summary'];
        $minDrop = $payload['options']['min_drop'];
        $out .= "Pares comparáveis: {$s['comparable_pairs']} | Subdimensionadas (<= -{$minDrop}%): {$s['undersized_count']} | Superdimensionadas (>= +{$minDrop}%): {$s['oversized_count']} | Normais: {$s['normal_count']}\n";

        $out .= $this->renderFriendlyGroup('SUBDIMENSIONADAS', $payload['undersized']);
        $out .= $this->renderFriendlyGroup('SUPERDIMENSIONADAS', $payload['oversized']);

        $out .= str_repeat('-', 100) . "\n";
        $out .= "[ COMPARATIVO CURSO x TIPO: sub vs super vs normal ]\n";
        $out .= str_repeat('-', 100) . "\n";
        $rows = [];
        foreach ($payload['cross_course_tipobg'] as $r) {
            $rows[] = [$r['course_tipobg'], $r['sub'], $r['super'], $r['normal']];
        }
        $out .= $this->formatTable(['Curso | Tipo', 'Sub', 'Super', 'Normal'], $rows) . "\n";

        return $out;
    }

    private function renderFriendlyGroup(string $title, array $group): string
    {
        $out = '';
        $out .= str_repeat('-', 100) . "\n";
        $out .= "[ {$title} — {$group['count']} turmas ]\n";
        $out .= str_repeat('-', 100) . "\n";

        if ($group['count'] === 0) {
            $out .= "(nenhuma)\n";
            return $out;
        }

        $out .= "\n>> Distribuição por CURSO (sufixo da turma):\n";
        foreach ($group['by_course'] as $row) {
            $out .= "   {$row['course']}: {$row['count']} ({$row['pct']}%)\n";
        }

        $out .= "\n>> Distribuição por TIPO (tipobg):\n";
        foreach ($group['by_tipobg'] as $row) {
            $out .= "   {$row['tipobg']}: {$row['count']} ({$row['pct']}%)\n";
        }

        $out .= "\n>> Distribuição por SEMESTRE IDEAL (numsemidl):\n";
        foreach ($group['by_semester'] as $row) {
            $out .= "   Semestre {$row['semester']}: {$row['count']} ({$row['pct']}%)\n";
        }

        $out .= "\n>> Cruzado CURSO x TIPO:\n";
        foreach ($group['course_x_tipobg'] as $cx) {
            $parts = collect($cx['tipos'])->map(fn($t) => "{$t['tipobg']}={$t['count']}")->implode(', ');
            $out .= "   {$cx['course']}: {$parts}\n";
        }

        $out .= "\n>> Lista detalhada:\n";
        $rows = [];
        foreach ($group['details'] as $d) {
            $rows[] = [
                $d['coddis'], $d['suffix'], $d['course'], $d['tipobg'], $d['semester'],
                $d['current_enrollment'], $d['baseline_avg'],
                sprintf('%+.2f%%', $d['variation_pct']), $d['deficit'],
            ];
        }
        $out .= $this->formatTable(['Disc', 'Turma', 'Curso', 'Tipo', 'Sem', 'Atual', 'Base', 'Var', 'Deficit'], $rows) . "\n";

        return $out;
    }

    private function renderMarkdown(array $payload): string
    {
        $cur = $payload['current_term'];
        $baseline = $payload['baseline_terms'];
        $baselineLabel = collect($baseline)->map(fn($t) => "{$t['period']} de {$t['year']}")->implode(', ');
        $minDrop = $payload['options']['min_drop'];

        $out = "## Análise de Padrões: {$cur['period']} de {$cur['year']} vs {$baselineLabel}\n\n";

        $s = $payload['summary'];
        $out .= "### Resumo\n\n";
        $out .= "| Métrica | Valor |\n|:--|--:|\n";
        $out .= "| Pares comparáveis | {$s['comparable_pairs']} |\n";
        $out .= "| Subdimensionadas (<= -{$minDrop}%) | {$s['undersized_count']} |\n";
        $out .= "| Superdimensionadas (>= +{$minDrop}%) | {$s['oversized_count']} |\n";
        $out .= "| Normais | {$s['normal_count']} |\n\n";

        $out .= $this->renderMarkdownGroup('Subdimensionadas', $payload['undersized'], $minDrop);
        $out .= $this->renderMarkdownGroup('Superdimensionadas', $payload['oversized'], $minDrop);

        $out .= "### Comparativo Curso x Tipo (sub vs super vs normal)\n\n";
        $out .= "| Curso | Tipo | Sub | Super | Normal |\n|:--|:--|--:|--:|--:|\n";
        foreach ($payload['cross_course_tipobg'] as $r) {
            [$course, $tipo] = array_map('trim', explode('|', $r['course_tipobg'], 2));
            $out .= "| {$course} | {$tipo} | {$r['sub']} | {$r['super']} | {$r['normal']} |\n";
        }
        $out .= "\n";

        return $out;
    }

    private function renderMarkdownGroup(string $title, array $group, float $minDrop): string
    {
        $out = "### {$title} — {$group['count']} turmas\n\n";

        if ($group['count'] === 0) {
            $out .= "_(nenhuma)_\n\n";
            return $out;
        }

        $out .= "**Distribuição por CURSO**\n\n";
        $out .= "| Curso | Qtd | % |\n|:--|--:|--:|\n";
        foreach ($group['by_course'] as $row) {
            $out .= "| {$row['course']} | {$row['count']} | {$row['pct']}% |\n";
        }
        $out .= "\n";

        $out .= "**Distribuição por TIPO (tipobg)**\n\n";
        $out .= "| Tipo | Qtd | % |\n|:--|--:|--:|\n";
        foreach ($group['by_tipobg'] as $row) {
            $out .= "| {$row['tipobg']} | {$row['count']} | {$row['pct']}% |\n";
        }
        $out .= "\n";

        $out .= "**Distribuição por SEMESTRE IDEAL (numsemidl)**\n\n";
        $out .= "| Semestre | Qtd | % |\n|:--|--:|--:|\n";
        foreach ($group['by_semester'] as $row) {
            $out .= "| {$row['semester']} | {$row['count']} | {$row['pct']}% |\n";
        }
        $out .= "\n";

        $out .= "**Cruzado CURSO x TIPO**\n\n";
        $out .= "| Curso | Tipos |\n|:--|:--|\n";
        foreach ($group['course_x_tipobg'] as $cx) {
            $parts = collect($cx['tipos'])->map(fn($t) => "{$t['tipobg']}={$t['count']}")->implode(', ');
            $out .= "| {$cx['course']} | {$parts} |\n";
        }
        $out .= "\n";

        $out .= "**Lista detalhada**\n\n";
        $out .= "| Disc | Turma | Curso | Tipo | Sem | Atual | Base | Var | Deficit |\n";
        $out .= "|:--|:--|:--|:--|:--|--:|--:|--:|--:|\n";
        foreach ($group['details'] as $d) {
            $out .= "| {$d['coddis']} | {$d['suffix']} | {$d['course']} | {$d['tipobg']} | {$d['semester']} | {$d['current_enrollment']} | {$d['baseline_avg']} | " . sprintf('%+.2f%%', $d['variation_pct']) . " | {$d['deficit']} |\n";
        }
        $out .= "\n";

        return $out;
    }

    private function formatTable(array $headers, array $rows): string
    {
        if (empty($rows)) return '(sem linhas)';

        $widths = [];
        foreach ($headers as $i => $h) {
            $widths[$i] = mb_strlen($h);
        }
        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $len = mb_strlen((string) $cell);
                if ($len > ($widths[$i] ?? 0)) $widths[$i] = $len;
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
                $table .= ' ' . $this->mbPad((string) $cell, $widths[$i]) . ' |';
            }
            $table .= "\n";
        }
        return $table;
    }

    private function mbPad(string $string, int $length): string
    {
        $diff = $length - mb_strlen($string);
        if ($diff <= 0) return $string;
        return $string . str_repeat(' ', $diff);
    }
}