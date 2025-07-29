<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\SchoolTerm;
use App\Models\SchoolClass;
// MODIFICADO: Importar a classe TableSeparator
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
    protected $description = 'Gera um relatório de vagas restantes nas salas alocadas, ordenado pela maior sobra de vagas.';

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

        $allocatedClasses = SchoolClass::whereBelongsTo($schoolterm)
            ->has('room')
            ->where('estmtr', '>', 0)
            ->with(['room', 'fusion.schoolclasses', 'classschedules'])
            ->withExists(['courseinformations as is_first_year_mandatory' => function ($query) {
                $query->where('tipobg', 'O')->whereIn('numsemidl', [1, 2]);
            }])
            ->get();

        if ($allocatedClasses->isEmpty()) {
            $this->info('Nenhuma turma com inscritos foi alocada a uma sala ainda. Não há dados para gerar o relatório.');
            return Command::SUCCESS;
        }

        $reportData = $allocatedClasses->map(function ($class) {
            $vagas = $class->room->assentos;

            if ($class->fusion) {
                $inscritos = $class->fusion->schoolclasses->sum('estmtr');
                $fusionCodes = $class->fusion->schoolclasses
                    ->pluck('coddis')
                    ->unique()
                    ->sort()
                    ->toArray();
                $coddis = implode('/', $fusionCodes);
            } else {
                $inscritos = $class->estmtr;
                $coddis = $class->coddis;
            }

            $diferenca = $vagas - $inscritos;

            $dayOrder = [
                'seg' => 1, 'ter' => 2, 'qua' => 3, 'qui' => 4, 'sex' => 5, 'sab' => 6, 'dom' => 7
            ];

            $horario = $class->classschedules
                ->sortBy(function ($schedule) use ($dayOrder) {
                    return $dayOrder[$schedule->diasmnocp] ?? 99;
                })
                ->map(function ($schedule) {
                    return "{$schedule->diasmnocp} {$schedule->horent}-{$schedule->horsai}";
                })
                ->implode("\n");

            return [
                'coddis' => $coddis,
                'codtur' => substr($class->codtur, -2),
                'horario' => $horario,
                'sala' => $class->room->nome,
                'vagas_sala' => $vagas,
                'inscritos' => $inscritos,
                'diferenca' => $diferenca,
                'is_first_year' => $class->is_first_year_mandatory,
            ];
        });

        $sortedData = $reportData->sortByDesc('diferenca');

        // MODIFICADO: Alterado de ->map() para um loop foreach para inserir separadores
        $tableRows = [];
        $totalItems = $sortedData->count();
        $currentItem = 0;

        foreach ($sortedData as $item) {
            $currentItem++;

            $diferenca = $item['diferenca'];
            $style = $diferenca < 0 ? 'red;options=bold' : ($diferenca < 10 ? 'yellow' : 'green');
            
            $firstYearText = $item['is_first_year'] ? '<fg=cyan;options=bold>Sim</>' : 'Não';

            // Adiciona a linha de dados
            $tableRows[] = [
                $item['coddis'],
                $item['codtur'],
                $item['horario'],
                $item['sala'],
                $item['vagas_sala'],
                $item['inscritos'],
                $firstYearText,
                "<fg={$style}>{$diferenca}</>",
            ];

            // Adiciona uma linha separadora, exceto após o último item
            if ($currentItem < $totalItems) {
                $tableRows[] = new TableSeparator();
            }
        }

        $headers = [
            'Cód. Disciplina',
            'Turma',
            'Horário',
            'Sala Alocada',
            'Vagas na Sala',
            'Inscritos (est.)',
            'Obrig. 1º Ano',
            'Sobra de Vagas',
        ];

        $this->table($headers, $tableRows);

        $this->line('');
        $this->info('Relatório gerado com sucesso.');

        return Command::SUCCESS;
    }
}