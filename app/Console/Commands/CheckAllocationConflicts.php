<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use App\Models\SchoolTerm;
use App\Models\SchoolClass;
use App\Services\SkuldPredictionService;
use Symfony\Component\Console\Helper\TableSeparator;

class CheckAllocationConflicts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'alocacao:verificar
                            {--output= : Salva o relatório em um arquivo de texto.}
                            {--margem=2 : Margem (assentos - estmtr) a partir da qual uma turma é considerada "apertada" e tem seu estmtr confirmado pelo Skuld.}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verifica a distribuição de salas do último período letivo em busca de conflitos de sala/horário (ignorando dobradinhas), de turmas cuja estimativa de alunos (estmtr) exceda o número de assentos da sala, e de turmas apertadas cuja predição do Skuld confirma estmtr ainda maior.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $schoolterm = SchoolTerm::getLatest();

        if (!$schoolterm) {
            $this->error('Nenhum período letivo encontrado. Por favor, cadastre um período primeiro.');
            return Command::FAILURE;
        }

        $this->displayHeader("Verificação de Alocação - {$schoolterm->period} de {$schoolterm->year}");

        // Carrega todas as turmas do período com as relações necessárias para
        // resolver a sala efetiva (sala própria ou sala do mestre da dobradinha)
        // e comparar horários.
        $turmas = SchoolClass::whereBelongsTo($schoolterm)
            ->with([
                'room',
                'fusion.schoolclasses.classschedules',
                'fusion.master.room',
                'classschedules',
            ])
            ->get();

        // Resolve a sala efetiva de cada turma (a que de fato ocupa) e mantém
        // apenas as que estão alocadas. Turmas em dobradinha usam a sala do
        // mestre; membros têm room_id nulo.
        $alocadas = $turmas->filter(function ($turma) {
            return !is_null($this->effectiveRoom($turma));
        })->values();

        if ($alocadas->isEmpty()) {
            $this->warn('Nenhuma turma alocada encontrada para o período.');
            return Command::SUCCESS;
        }

        $this->info("Analisando {$alocadas->count()} turmas alocadas...");
        $this->newLine();

        // ----------------------------------------------------------------
        // 1. Detecção de conflitos de sala/horário (excluindo dobradinhas)
        // ----------------------------------------------------------------
        $conflitos = $this->detectarConflitos($alocadas);

        // ----------------------------------------------------------------
        // 2. Verificação de capacidade (estmtr > assentos)
        // ----------------------------------------------------------------
        $capacidade = $this->verificarCapacidade($alocadas);

        // ----------------------------------------------------------------
        // 3. Turmas "apertadas" (estmtr dentro da margem do limite) cuja
        //    predição do Skuld é consultada para confirmar se o modelo prevê
        //    estmtr ainda maior (e eventualmente acima da capacidade).
        // ----------------------------------------------------------------
        $margem = (int) $this->option('margem');
        $apertadas = $this->detectarApertadas($alocadas, $margem, $capacidade);
        $apertadas = $this->confirmarComSkuld($apertadas, $schoolterm);

        // ----------------------------------------------------------------
        // Geração do relatório (console e/ou arquivo)
        // ----------------------------------------------------------------
        $this->gerarRelatorio($conflitos, $capacidade, $apertadas, $alocadas->count());

        return Command::SUCCESS;
    }

    /**
     * Retorna a sala efetiva da turma (própria ou do mestre da dobradinha).
     */
    private function effectiveRoom(SchoolClass $turma)
    {
        if ($turma->fusion_id && $turma->fusion && $turma->fusion->master) {
            return $turma->fusion->master->room;
        }

        return $turma->room;
    }

    /**
     * Detecta pares de turmas alocadas na mesma sala com horário em conflito,
     * ignorando pares que pertencem à mesma dobradinha (mesma fusion).
     */
    private function detectarConflitos($alocadas): array
    {
        // Agrupa por id da sala efetiva.
        $porSala = $alocadas->groupBy(function ($turma) {
            return $this->effectiveRoom($turma)->id;
        });

        $conflitos = [];
        foreach ($porSala as $roomId => $turmasDaSala) {
            $sala = $this->effectiveRoom($turmasDaSala->first());
            $count = $turmasDaSala->count();

            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $t1 = $turmasDaSala[$i];
                    $t2 = $turmasDaSala[$j];

                    // Dobradinhas (mesma fusão) compartilham sala e horário
                    // intencionalmente: nunca são conflito.
                    if ($t1->fusion_id && $t2->fusion_id && $t1->fusion_id === $t2->fusion_id) {
                        continue;
                    }

                    if ($t1->isInConflict($t2)) {
                        $conflitos[] = [
                            'sala' => $sala->nome,
                            'turma1' => $this->identificar($t1),
                            'turma2' => $this->identificar($t2),
                            'horario' => $this->horarioComum($t1, $t2),
                        ];
                    }
                }
            }
        }

        return $conflitos;
    }

    /**
     * Itera sobre as unidades de alocação (turma isolada ou dobradinha),
     * processando cada uma apenas uma vez. O mestre da dobradinha representa
     * o grupo; turmas isoladas representam a si mesmas.
     */
    private function iterarUnidades($alocadas): \Generator
    {
        $processadas = [];

        foreach ($alocadas as $turma) {
            if ($turma->fusion_id) {
                if ($turma->fusion->master_id !== $turma->id) {
                    continue;
                }
                if (in_array($turma->fusion_id, $processadas)) {
                    continue;
                }
                $processadas[] = $turma->fusion_id;

                $membros = $turma->fusion->schoolclasses;
                $sala = $this->effectiveRoom($turma);
                $estmtr = $membros->sum('estmtr');
                $unidade = $membros->pluck('coddis')->unique()->sort()->implode('/')
                    . ' (dobradinha: ' . $membros->map(function ($m) {
                        return $m->coddis . '-' . substr($m->codtur, -2);
                    })->implode(', ') . ')';
            } else {
                if (in_array('t' . $turma->id, $processadas)) {
                    continue;
                }
                $processadas[] = 't' . $turma->id;

                $membros = collect([$turma]);
                $sala = $this->effectiveRoom($turma);
                $estmtr = $turma->estmtr;
                $unidade = $turma->coddis . '-' . substr($turma->codtur, -2);
            }

            yield [
                'sala' => $sala,
                'estmtr' => $estmtr,
                'unidade' => $unidade,
                'membros' => $membros,
            ];
        }
    }

    /**
     * Verifica, por unidade de alocação (turma isolada ou dobradinha), se a
     * estimativa de alunos excede o número de assentos da sala.
     */
    private function verificarCapacidade($alocadas): array
    {
        $violacoes = [];

        foreach ($this->iterarUnidades($alocadas) as $u) {
            // Só reporta quando há estimativa conhecida e ela excede a sala.
            if (!is_null($u['estmtr']) && $u['estmtr'] > $u['sala']->assentos) {
                $violacoes[] = [
                    'sala' => $u['sala']->nome,
                    'unidade' => $u['unidade'],
                    'estmtr' => $u['estmtr'],
                    'assentos' => $u['sala']->assentos,
                    'excesso' => $u['estmtr'] - $u['sala']->assentos,
                ];
            }
        }

        return $violacoes;
    }

    /**
     * Detecta as unidades "apertadas": aquelas cuja estimativa NÃO excede a
     * sala (essas vão para a seção de capacidade excedida) mas está dentro da
     * margem informada do limite (assentos - estmtr <= margem). Essas turmas
     * têm o estmtr confirmado pelo Skuld logo em seguida.
     *
     * @param int $margem Distância máxima do limite para ser considerada apertada.
     * @param array $capacidade Lista de unidades já excedidas (para não repetir).
     */
    private function detectarApertadas($alocadas, int $margem, array $capacidade): array
    {
        $excedidas = collect($capacidade)->pluck('estmtr', 'unidade')->all();

        $apertadas = [];
        foreach ($this->iterarUnidades($alocadas) as $u) {
            $estmtr = $u['estmtr'];
            $assentos = $u['sala']->assentos;

            if (is_null($estmtr)) {
                continue;
            }
            // Já excede -> vai para a seção de capacidade, não repetir aqui.
            if ($estmtr > $assentos) {
                continue;
            }
            // Só é apertada se estiver dentro da margem do limite.
            if (($assentos - $estmtr) > $margem) {
                continue;
            }

            $apertadas[] = [
                'sala' => $u['sala']->nome,
                'unidade' => $u['unidade'],
                'estmtr' => $estmtr,
                'assentos' => $assentos,
                'margem_restante' => $assentos - $estmtr,
                'membros' => $u['membros']->map(function ($m) {
                    return ['coddis' => $m->coddis, 'codtur' => $m->codtur];
                })->values()->toArray(),
                'estmtr_skuld' => null,
                'skuld_status' => 'pendente',
                'skuld_excede' => false,
                'skuld_delta' => null,
            ];
        }

        return $apertadas;
    }

    /**
     * Consulta o Skuld uma única vez para o semestre e, para cada turma
     * apertada, soma as predições de seus membros, indicando se o modelo
     * prevê estmtr ainda maior (e eventualmente acima da capacidade).
     */
    private function confirmarComSkuld(array $apertadas, SchoolTerm $term): array
    {
        if (empty($apertadas)) {
            return $apertadas;
        }

        $this->line('Consultando o Skuld para confirmar a predição das turmas apertadas (pode levar alguns segundos)...');

        try {
            $predicoes = (new SkuldPredictionService())->predict($term);
        } catch (\Throwable $e) {
            $this->warn('Não foi possível consultar o Skuld: ' . $e->getMessage());
            $predicoes = [];
        }

        if (empty($predicoes)) {
            foreach ($apertadas as &$a) {
                $a['skuld_status'] = 'indisponivel';
            }
            unset($a);

            return $apertadas;
        }

        foreach ($apertadas as &$a) {
            $soma = 0;
            $encontrados = 0;

            foreach ($a['membros'] as $m) {
                $key = $m['coddis'] . '|' . $m['codtur'];
                if (array_key_exists($key, $predicoes)) {
                    $soma += $predicoes[$key];
                    $encontrados++;
                }
            }

            if ($encontrados > 0) {
                $a['estmtr_skuld'] = $soma;
                $a['skuld_excede'] = $soma > $a['assentos'];
                $a['skuld_delta'] = $soma - $a['estmtr'];
                $a['skuld_status'] = 'ok';
            } else {
                $a['skuld_status'] = 'sem_predicao';
            }
        }
        unset($a);

        return $apertadas;
    }

    /**
     * Identificador curto da turma para exibição.
     */
    private function identificar(SchoolClass $turma): string
    {
        return $turma->coddis . '-' . substr($turma->codtur, -2);
    }

    /**
     * Mostra o(s) horário(s) em comum entre duas turmas em conflito.
     */
    private function horarioComum(SchoolClass $t1, SchoolClass $t2): string
    {
        $comuns = [];
        foreach ($t1->classschedules as $cs1) {
            foreach ($t2->classschedules as $cs2) {
                if ($cs1->id === $cs2->id) {
                    $comuns[$cs1->id] = "{$cs1->diasmnocp} {$cs1->horent}-{$cs1->horsai}";
                }
            }
        }

        // Se não houver horário idêntico (conflito parcial por sobreposição),
        // exibe os horários de ambas separados.
        if (empty($comuns)) {
            return $this->formatSchedule($t1) . '  ||  ' . $this->formatSchedule($t2);
        }

        return implode(' ; ', array_values($comuns));
    }

    /**
     * Formata os horários de uma turma para exibição.
     */
    private function formatSchedule(SchoolClass $class): string
    {
        $dayOrder = [
            'seg' => 1, 'ter' => 2, 'qua' => 3, 'qui' => 4, 'sex' => 5, 'sab' => 6, 'dom' => 7,
        ];

        return $class->classschedules
            ->sortBy(fn($schedule) => $dayOrder[$schedule->diasmnocp] ?? 99)
            ->map(fn($schedule) => "{$schedule->diasmnocp} {$schedule->horent}-{$schedule->horsai}")
            ->implode(', ');
    }

    /**
     * Gera e exibe o relatório no console e/ou em arquivo.
     */
    private function gerarRelatorio(array $conflitos, array $capacidade, array $apertadas, int $totalTurmas): void
    {
        $outputToFile = $this->option('output');
        $reportContent = '';

        // Conta quantas turmas apertadas o Skuld confirmou com estmtr maior
        // (para destacar no resumo).
        $skuldMaior = collect($apertadas)
            ->filter(fn($a) => $a['skuld_status'] === 'ok' && $a['skuld_delta'] > 0)
            ->count();
        $skuldExcede = collect($apertadas)
            ->filter(fn($a) => $a['skuld_excede'])
            ->count();

        $semProblemas = empty($conflitos) && empty($capacidade) && empty($apertadas);

        if ($semProblemas) {
            $mensagem = '(✓) Nenhum conflito de sala/horário, problema de capacidade ou turma apertada encontrado.';
            $resumo = "Resumo: {$totalTurmas} turmas analisadas, 0 conflitos, 0 problemas de capacidade, 0 apertadas.";

            if ($outputToFile) {
                $reportContent .= $mensagem . "\n\n" . $resumo . "\n";
                File::put($outputToFile, $reportContent);
                $this->info("Relatório salvo em: {$outputToFile}");
            } else {
                $this->info($mensagem);
                $this->line('');
                $this->line(str_repeat('-', 70));
                $this->info($resumo);
                $this->line(str_repeat('-', 70));
            }

            return;
        }

        // Seção 1: Conflitos de sala/horário
        if (!empty($conflitos)) {
            $titulo = '[ SEÇÃO 1: CONFLITOS DE SALA/HORÁRIO ]';
            $subtitulo = 'Duas ou mais turmas alocadas na mesma sala com horários sobrepostos (dobradinhas não são consideradas conflito).';
            $headers = ['Sala', 'Turma 1', 'Turma 2', 'Horário em Conflito'];
            $rows = array_map('array_values', $conflitos);

            if ($outputToFile) {
                $reportContent .= $titulo . "\n" . $subtitulo . "\n\n" . $this->formatTableForFile($headers, $rows);
            } else {
                $this->error($titulo);
                $this->line($subtitulo);
                $this->newLine();
                $this->table($headers, $rows);
            }
        }

        // Separador entre seções
        if (!empty($conflitos) && (!empty($capacidade) || !empty($apertadas))) {
            if ($outputToFile) {
                $reportContent .= "\n";
            } else {
                $this->newLine();
            }
        }

        // Seção 2: Capacidade excedida
        if (!empty($capacidade)) {
            $titulo = '[ SEÇÃO 2: CAPACIDADE EXCEDIDA (estmtr > assentos) ]';
            $subtitulo = 'Turmas (ou dobradinhas) cuja estimativa de matriculados supera o número de assentos da sala alocada.';
            $headers = ['Sala', 'Unidade (Disciplina-Turma)', 'Estmtr', 'Assentos', 'Excesso'];

            if ($outputToFile) {
                $reportContent .= $titulo . "\n" . $subtitulo . "\n\n" . $this->formatTableForFile($headers, $capacidade);
            } else {
                $this->error($titulo);
                $this->line($subtitulo);
                $this->newLine();
                $this->table($headers, $capacidade);
            }
        }

        // Separador entre seção 2 e 3
        if (!empty($capacidade) && !empty($apertadas)) {
            if ($outputToFile) {
                $reportContent .= "\n";
            } else {
                $this->newLine();
            }
        }

        // Seção 3: Turmas apertadas confirmadas pelo Skuld
        if (!empty($apertadas)) {
            $titulo = '[ SEÇÃO 3: TURMAS APERTADAS (confirmação via Skuld) ]';
            $subtitulo = 'Turmas cuja estimativa está dentro da margem do limite da sala. O Skuld foi consultado para confirmar se o modelo prevê estmtr ainda maior.';
            $headers = ['Sala', 'Unidade (Disciplina-Turma)', 'Estmtr', 'Assentos', 'Margem', 'Pred. Skuld', 'Skuld excede?'];

            // Monta as linhas: com cores no console; texto puro no arquivo.
            $rowsConsole = [];
            $rowsFile = [];
            foreach ($apertadas as $a) {
                if ($outputToFile) {
                    $rowsFile[] = [
                        $a['sala'], $a['unidade'], $a['estmtr'], $a['assentos'], $a['margem_restante'],
                        $this->formatSkuldCell($a, true), $this->formatSkuldExcedeCell($a, true),
                    ];
                } else {
                    $rowsConsole[] = [
                        $a['sala'], $a['unidade'], $a['estmtr'], $a['assentos'], $a['margem_restante'],
                        $this->formatSkuldCell($a, false), $this->formatSkuldExcedeCell($a, false),
                    ];
                }
            }

            if ($outputToFile) {
                $reportContent .= $titulo . "\n" . $subtitulo . "\n\n" . $this->formatTableForFile($headers, $rowsFile);
            } else {
                $this->warn($titulo);
                $this->line($subtitulo);
                $this->newLine();
                $this->table($headers, $rowsConsole);
            }
        }

        // Resumo final
        $resumo = sprintf(
            'Resumo: %d turmas analisadas, %d conflito(s) de sala/horário, %d problema(s) de capacidade, %d apertada(s) (Skuld prevê maior em %d, excedendo em %d).',
            $totalTurmas,
            count($conflitos),
            count($capacidade),
            count($apertadas),
            $skuldMaior,
            $skuldExcede
        );

        if ($outputToFile) {
            $reportContent .= "\n" . str_repeat('-', 70) . "\n" . $resumo . "\n";
            File::put($outputToFile, $reportContent);
            $this->info("Relatório salvo em: {$outputToFile}");
        } else {
            $this->newLine();
            $this->line(str_repeat('-', 70));
            $this->info($resumo);
            $this->line(str_repeat('-', 70));
        }
    }

    /**
     * Formata a célula da predição do Skuld para a tabela.
     */
    private function formatSkuldCell(array $a, bool $forFile): string
    {
        switch ($a['skuld_status']) {
            case 'ok':
                $valor = $a['estmtr_skuld'];
                $delta = $a['skuld_delta'];
                $sufixo = $delta > 0 ? " (+{$delta})" : ($delta < 0 ? " ({$delta})" : ' (=)');
                if ($forFile) {
                    return $valor . $sufixo;
                }
                $style = $a['skuld_excede'] ? 'red;options=bold'
                    : ($delta > 0 ? 'yellow' : 'green');
                return "<fg={$style}>{$valor}{$sufixo}</>";
            case 'sem_predicao':
                return $forFile ? 'sem predição' : '<fg=gray>sem predição</>';
            case 'indisponivel':
            default:
                return $forFile ? 'indisponível' : '<fg=gray>indisponível</>';
        }
    }

    /**
     * Formata a célula "Skuld excede?" para a tabela.
     */
    private function formatSkuldExcedeCell(array $a, bool $forFile): string
    {
        switch ($a['skuld_status']) {
            case 'ok':
                $sim = $a['skuld_excede'];
                if ($forFile) {
                    return $sim ? 'SIM' : 'não';
                }
                return $sim ? '<fg=red;options=bold>SIM</>' : '<fg=green>não</>';
            case 'sem_predicao':
                return $forFile ? '-' : '<fg=gray>-</>';
            case 'indisponivel':
            default:
                return $forFile ? '?' : '<fg=gray>?</>';
        }
    }

    /**
     * Formata uma tabela para ser salva em um arquivo de texto.
     */
    private function formatTableForFile(array $headers, array $rows): string
    {
        $columnWidths = [];
        foreach ($headers as $index => $header) {
            $columnWidths[$index] = strlen($header);
        }

        foreach ($rows as $row) {
            foreach ($row as $index => $cell) {
                $width = strlen((string)$cell);
                if (!isset($columnWidths[$index]) || $width > $columnWidths[$index]) {
                    $columnWidths[$index] = $width;
                }
            }
        }

        $tableString = '|';
        foreach ($headers as $index => $header) {
            $tableString .= ' ' . str_pad($header, $columnWidths[$index]) . ' |';
        }
        $tableString .= "\n|";

        foreach ($headers as $index => $header) {
            $tableString .= ':' . str_repeat('-', $columnWidths[$index]) . ':|';
        }
        $tableString .= "\n";

        foreach ($rows as $row) {
            $tableString .= '|';
            foreach ($row as $index => $cell) {
                $tableString .= ' ' . str_pad((string)$cell, $columnWidths[$index]) . ' |';
            }
            $tableString .= "\n";
        }

        return $tableString;
    }

    /**
     * Exibe um cabeçalho formatado no console.
     */
    private function displayHeader(string $title): void
    {
        $width = 80;
        $this->info(str_repeat('=', $width));
        $this->info(str_pad("  " . $title, $width - 1) . " ");
        $this->info(str_repeat('=', $width));
        $this->newLine();
    }
}