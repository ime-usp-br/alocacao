<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\SchoolTerm;
use App\Models\SchoolClass;
use Illuminate\Support\Facades\File;
use Exception;

class ReportDiscrepancies extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'schoolclass:report-discrepancies
                            {--output= : Salva o relatório em um arquivo de texto.}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Gera um relatório de discrepâncias entre as turmas do sistema local e as do Replicado.';

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

        $this->displayHeader("Relatório de Discrepâncias de Turmas - {$schoolterm->period} de {$schoolterm->year}");

        try {
            $replicadoClassesRaw = SchoolClass::getFromReplicadoBySchoolTerm($schoolterm);
        } catch (Exception $e) {
            $this->error('(✗) Erro: Não foi possível conectar ao banco de dados Replicado. Verifique a conexão e as credenciais.');
            // Opcional: logar o erro real para depuração
            // \Log::error('Erro ao conectar ao Replicado: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $localClasses = SchoolClass::whereBelongsTo($schoolterm)
            ->with(['instructors', 'classschedules', 'room'])
            ->get();

        $progressBar = $this->output->createProgressBar(count($localClasses) + count($replicadoClassesRaw));
        $progressBar->setFormat("Processando turmas: [%bar%] %percent:3s%%");
        $progressBar->start();

        // Estruturas para facilitar a busca
        $localClassesByKey = $localClasses->keyBy(function ($item) {
            return $item->coddis . '-' . substr($item->codtur, -2);
        });

        $replicadoClassesByKey = collect($replicadoClassesRaw)->keyBy(function ($item) {
            return $item['coddis'] . '-' . substr($item['codtur'], -2);
        });

        $divergentData = [];
        $newInReplicado = [];

        // Compara Replicado -> Local
        foreach ($replicadoClassesByKey as $key => $replicadoClass) {
            if ($localClassesByKey->has($key)) {
                $localClass = $localClassesByKey[$key];
                $differences = $this->compareClasses($localClass, $replicadoClass);
                if (!empty($differences)) {
                    foreach ($differences as $diff) {
                        $divergentData[] = [
                            'coddis' => $localClass->coddis,
                            'codtur' => substr($localClass->codtur, -2),
                            'field' => $diff['field'],
                            'local' => $diff['local'],
                            'replicado' => $diff['replicado'],
                        ];
                    }
                }
            } else {
                $newInReplicado[] = [
                    'coddis' => $replicadoClass['coddis'],
                    'codtur' => substr($replicadoClass['codtur'], -2),
                ];
            }
            $progressBar->advance();
        }

        // Encontra turmas removidas (existem no local mas não no replicado)
        $removedFromReplicado = [];
        foreach ($localClassesByKey as $key => $localClass) {
            if (!$replicadoClassesByKey->has($key)) {
                $removedFromReplicado[] = [
                    'coddis' => $localClass->coddis,
                    'codtur' => substr($localClass->codtur, -2),
                    'sala' => $localClass->room->nome ?? 'N/A',
                ];
            }
            $progressBar->advance();
        }
        
        $progressBar->finish();
        $this->newLine(2);

        $this->generateReport(
            $divergentData,
            $newInReplicado,
            $removedFromReplicado
        );

        return Command::SUCCESS;
    }

    /**
     * Compara uma turma local com os dados do replicado.
     */
    private function compareClasses(SchoolClass $local, array $replicado): array
    {
        $differences = [];

        // Comparar Horários
        $localSchedules = $local->classschedules->map(function ($schedule) {
            return "{$schedule->diasmnocp} {$schedule->horent}-{$schedule->horsai}";
        })->sort()->implode(', ');

        $replicadoSchedules = collect($replicado['class_schedules'])->map(function ($schedule) {
            return "{$schedule['diasmnocp']} {$schedule['horent']}-{$schedule['horsai']}";
        })->sort()->implode(', ');

        if ($localSchedules !== $replicadoSchedules) {
            $differences[] = ['field' => 'Horário', 'local' => $localSchedules ?: 'N/A', 'replicado' => $replicadoSchedules ?: 'N/A'];
        }

        // Comparar Professores (codpes e nompes)
        $localInstructors = $local->instructors->map(function ($instructor) {
            return "{$instructor->codpes} - {$instructor->nompes}";
        })->sort()->implode(', ');

        // Filtra arrays vazios antes de mapear os dados do replicado
        $replicadoInstructors = collect($replicado['instructors'])
            ->filter(function ($instructor) {
                return !empty($instructor) && isset($instructor['codpes']);
            })
            ->map(function ($instructor) {
                return "{$instructor['codpes']} - {$instructor['nompes']}";
            })->sort()->implode(', ');
        
        if ($localInstructors !== $replicadoInstructors) {
            $field = 'Professor(es)';
            
            $localCodpes = $local->instructors->pluck('codpes')->sort()->values()->all();
            
            // Filtra arrays vazios antes de extrair os codpes do replicado
            $replicadoCodpes = collect($replicado['instructors'])
                ->filter(function ($instructor) {
                    return !empty($instructor) && isset($instructor['codpes']);
                })
                ->pluck('codpes')->sort()->values()->all();

            if ($localCodpes === $replicadoCodpes) {
                $field = 'Nome do Professor';
            }
            $differences[] = ['field' => $field, 'local' => $localInstructors ?: 'N/A', 'replicado' => $replicadoInstructors ?: 'N/A'];
        }

        return $differences;
    }

    /**
     * Gera e exibe o relatório no console ou em arquivo.
     */
    private function generateReport(array $divergent, array $new, array $removed): void
    {
        $outputToFile = $this->option('output');
        $reportContent = '';
    
        if (empty($divergent) && empty($new) && empty($removed)) {
            $message = '(✓) Nenhuma discrepância encontrada.';
            $summary = "Resumo: 0 turmas modificadas, 0 turmas novas, 0 turmas removidas.";
            
            if ($outputToFile) {
                $reportContent .= $message . "\n\n";
                $reportContent .= "-------------------------------------------------------------------\n";
                $reportContent .= $summary . "\n";
                $reportContent .= "-------------------------------------------------------------------\n";
            } else {
                $this->info($message);
                $this->line('');
                $this->line('-------------------------------------------------------------------');
                $this->info($summary);
                $this->line('-------------------------------------------------------------------');
            }
        } else {
            // Seção 1
            if (!empty($divergent)) {
                $header = "[ SEÇÃO 1: TURMAS COM DADOS DIVERGENTES ]\n";
                $subHeader = "As seguintes turmas apresentam dados diferentes entre o sistema local e o Replicado.\n";
                $headers = ['Disciplina', 'Turma', 'Campo Alterado', 'Valor no Sistema', 'Valor no Replicado'];
                $rows = collect($divergent)->map(fn($item) => array_values($item))->toArray();

                if ($outputToFile) {
                    $reportContent .= $header . $subHeader . "\n";
                    $reportContent .= $this->formatTableForFile($headers, $rows);
                } else {
                    $this->warn($header);
                    $this->line($subHeader);
                    $this->table($headers, $rows);
                }
            }
    
            // Seção 2
            if (!empty($new)) {
                $header = "[ SEÇÃO 2: TURMAS NOVAS (Apenas no Replicado) ]\n";
                $subHeader = "As seguintes turmas foram encontradas no Replicado e devem ser importadas.\n";
                $headers = ['Disciplina', 'Turma'];
                $rows = collect($new)->map(fn($item) => array_values($item))->toArray();

                if ($outputToFile) {
                    $reportContent .= "\n" . $header . $subHeader . "\n";
                    $reportContent .= $this->formatTableForFile($headers, $rows);
                } else {
                    $this->line('');
                    $this->warn($header);
                    $this->line($subHeader);
                    $this->table($headers, $rows);
                }
            }
    
            // Seção 3
            if (!empty($removed)) {
                $header = "[ SEÇÃO 3: TURMAS REMOVIDAS (Canceladas no Replicado) ]\n";
                $subHeader = "As seguintes turmas existem no sistema, mas não no Replicado. Verifique se foram canceladas.\n";
                $headers = ['Disciplina', 'Turma', 'Sala Alocada'];
                $rows = collect($removed)->map(fn($item) => array_values($item))->toArray();

                if ($outputToFile) {
                    $reportContent .= "\n" . $header . $subHeader . "\n";
                    $reportContent .= $this->formatTableForFile($headers, $rows);
                } else {
                    $this->line('');
                    $this->warn($header);
                    $this->line($subHeader);
                    $this->table($headers, $rows);
                }
            }
    
            // Resumo
            $summary = sprintf(
                "Resumo: %d turmas modificadas, %d turmas novas, %d turmas removidas.",
                count($divergent),
                count($new),
                count($removed)
            );
    
            if ($outputToFile) {
                $reportContent .= "\n-------------------------------------------------------------------\n";
                $reportContent .= $summary . "\n";
            } else {
                $this->line('');
                $this->line('-------------------------------------------------------------------');
                $this->info($summary);
            }
        }
    
        if ($outputToFile) {
            File::put($outputToFile, $reportContent);
            $this->info("Relatório salvo em: {$outputToFile}");
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
                $width = strlen($cell);
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