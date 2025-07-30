<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\SchoolTerm;
use App\Models\SchoolClass;
use Symfony\Component\Console\Helper\TableSeparator;

class ReportRoomVacancy extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'report:room-vacancy';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Gera um relatório de vagas restantes nas salas alocadas e lista as turmas internas sem sala, tudo em uma única tabela.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $schoolterm = SchoolTerm::getLatest();

        if (!$schoolterm) {
            $this->error('Nenhum período letivo ativo encontrado. Por favor, cadastre um período primeiro.');
            return Command::FAILURE;
        }

        $this->info("Gerando relatório de ocupação de salas para o período: {$schoolterm->period} de {$schoolterm->year}");
        $this->line('');

        // 1. Obter turmas alocadas (turmas que são mestres de suas fusões e possuem sala)
        $allocatedClasses = SchoolClass::whereBelongsTo($schoolterm)
            ->has('room')
            ->where('estmtr', '>', 0)
            ->with(['room', 'fusion.schoolclasses', 'classschedules'])
            ->withExists(['courseinformations as is_first_year_mandatory' => function ($query) {
                $query->where('tipobg', 'O')->whereIn('numsemidl', [1, 2]);
            }])
            ->get();

        // 2. Obter turmas internas não alocadas
        // A lógica foi ajustada para considerar uma turma não alocada somente se:
        // - Ela não tem sala diretamente.
        // - E (ela não pertence a uma fusão OU a turma mestre de sua fusão também não tem sala).
        // Isso impede que turmas de uma dobradinha já alocada apareçam nesta lista.
        $unallocatedClasses = SchoolClass::whereBelongsTo($schoolterm)
            ->where('externa', false) // Apenas turmas internas
            ->doesntHave('room')      // A própria turma não deve ter sala
            ->where(function ($query) {
                // E ou ela não está em fusão, ou o mestre da sua fusão também não tem sala
                $query->doesntHave('fusion')
                    ->orWhereHas('fusion', function ($q) {
                        $q->doesntHave('master.room');
                    });
            })
            ->where('estmtr', '>', 0)
            ->with(['fusion.schoolclasses', 'classschedules'])
            ->withExists(['courseinformations as is_first_year_mandatory' => function ($query) {
                $query->where('tipobg', 'O')->whereIn('numsemidl', [1, 2]);
            }])
            ->get();

        if ($allocatedClasses->isEmpty() && $unallocatedClasses->isEmpty()) {
            $this->info('Nenhuma turma com inscritos (alocada ou não) encontrada para gerar o relatório.');
            return Command::SUCCESS;
        }

        // 3. Processar e ordenar dados das turmas alocadas
        $allocatedData = $allocatedClasses->map(function ($class) {
            $vagas = $class->room->assentos;
            $inscritos = $class->fusion ? $class->fusion->schoolclasses->sum('estmtr') : $class->estmtr;
            $coddis = $class->fusion ? implode('/', $class->fusion->schoolclasses->pluck('coddis')->unique()->sort()->toArray()) : $class->coddis;

            return [
                'coddis' => $coddis,
                'codtur' => substr($class->codtur, -2),
                'horario' => $this->formatSchedule($class),
                'sala' => $class->room->nome,
                'vagas_sala' => $vagas,
                'inscritos' => $inscritos,
                'diferenca' => $vagas - $inscritos,
                'is_first_year' => $class->is_first_year_mandatory,
            ];
        })->sortByDesc('diferenca');

        // 4. Processar e ordenar dados das turmas não alocadas
        // Adicionado filtro para processar cada fusão/turma apenas uma vez
        $unallocatedData = $unallocatedClasses
            ->filter(function ($class) {
                // Mantém a turma se ela não faz parte de uma fusão,
                // ou se ela É a mestre da sua fusão, evitando duplicatas.
                return !$class->fusion_id || $class->id === $class->fusion->master_id;
            })
            ->map(function ($class) {
                $inscritos = $class->fusion ? $class->fusion->schoolclasses->sum('estmtr') : $class->estmtr;
                $coddis = $class->fusion ? implode('/', $class->fusion->schoolclasses->pluck('coddis')->unique()->sort()->toArray()) : $class->coddis;

                return [
                    'coddis' => $coddis,
                    'codtur' => substr($class->codtur, -2),
                    'horario' => $this->formatSchedule($class),
                    'sala' => '<fg=gray>N/A</>',
                    'vagas_sala' => '<fg=gray>-</>',
                    'inscritos' => $inscritos,
                    'diferenca' => '<fg=gray>-</>',
                    'is_first_year' => $class->is_first_year_mandatory,
                ];
            })->sortByDesc('inscritos');

        // 5. Montar as linhas da tabela combinada
        $tableRows = [];

        // Adiciona turmas alocadas
        foreach ($allocatedData as $item) {
            $diferenca = $item['diferenca'];
            $style = $diferenca < 0 ? 'red;options=bold' : ($diferenca < 10 ? 'yellow' : 'green');
            $firstYearText = $item['is_first_year'] ? '<fg=cyan;options=bold>Sim</>' : 'Não';
            
            $tableRows[] = [
                $item['coddis'], $item['codtur'], $item['horario'], $item['sala'],
                $item['vagas_sala'], $item['inscritos'], $firstYearText, "<fg={$style}>{$diferenca}</>"
            ];
            $tableRows[] = new TableSeparator();
        }

        // Adiciona o separador se ambas as seções existirem
        if (!$allocatedData->isEmpty() && !$unallocatedData->isEmpty()) {
            // Remove o último separador para não ficar duplicado
            if (end($tableRows) instanceof TableSeparator) {
                array_pop($tableRows);
            }
            $tableRows[] = new TableSeparator();
            $tableRows[] = ['<fg=magenta;options=bold>--- TURMAS SEM SALA (ORDENADAS POR INSCRITOS) ---</>'];
            $tableRows[] = new TableSeparator();
        }

        // Adiciona turmas não alocadas
        foreach ($unallocatedData as $item) {
             $firstYearText = $item['is_first_year'] ? '<fg=cyan;options=bold>Sim</>' : 'Não';
            $tableRows[] = [
                $item['coddis'], $item['codtur'], $item['horario'], $item['sala'],
                $item['vagas_sala'], $item['inscritos'], $firstYearText, $item['diferenca']
            ];
            $tableRows[] = new TableSeparator();
        }

        // Remove o último separador da tabela
        if (end($tableRows) instanceof TableSeparator) {
            array_pop($tableRows);
        }

        // 6. Imprimir a tabela
        $headers = [
            'Cód. Disciplina', 'Turma', 'Horário', 'Sala Alocada',
            'Vagas na Sala', 'Inscritos (est.)', 'Obrig. 1º Ano', 'Sobra de Vagas',
        ];

        $this->table($headers, $tableRows);
        $this->line('');
        $this->info('Relatório gerado com sucesso.');

        return Command::SUCCESS;
    }

    /**
     * Formata os horários de uma turma para exibição.
     */
    private function formatSchedule(SchoolClass $class): string
    {
        $dayOrder = [
            'seg' => 1, 'ter' => 2, 'qua' => 3, 'qui' => 4, 'sex' => 5, 'sab' => 6, 'dom' => 7
        ];

        return $class->classschedules
            ->sortBy(fn($schedule) => $dayOrder[$schedule->diasmnocp] ?? 99)
            ->map(fn($schedule) => "{$schedule->diasmnocp} {$schedule->horent}-{$schedule->horsai}")
            ->implode("\n");
    }
}