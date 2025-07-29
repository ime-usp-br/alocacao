<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\SchoolTerm;
use App\Models\SchoolClass;

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
            ->with(['room', 'fusion.schoolclasses'])
            // Adiciona uma subquery para verificar se a turma é obrigatória e de 1º ano.
            // O resultado será um atributo booleano 'is_first_year_mandatory' em cada modelo.
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
            $inscritos = $class->estmtr;
            $diferenca = $vagas - $inscritos;

            // Verifica se a turma faz parte de uma dobradinha
            $coddis = $class->coddis;
            if ($class->fusion) {
                // Constrói o código da dobradinha no formato coddis1/coddis2
                $fusionCodes = $class->fusion->schoolclasses
                    ->pluck('coddis')
                    ->unique()
                    ->sort()
                    ->toArray();
                $coddis = implode('/', $fusionCodes);
            }

            return [
                'coddis' => $coddis,
                'codtur' => substr($class->codtur, -2),
                'sala' => $class->room->nome,
                'vagas_sala' => $vagas,
                'inscritos' => $inscritos,
                'diferenca' => $diferenca,
                'is_first_year' => $class->is_first_year_mandatory, // Atributo adicionado pela query
            ];
        });

        $sortedData = $reportData->sortByDesc('diferenca');

        $tableRows = $sortedData->map(function ($item) {
            $diferenca = $item['diferenca'];
            $style = $diferenca < 0 ? 'red;options=bold' : ($diferenca < 10 ? 'yellow' : 'green');
            
            // Formata a nova coluna
            $firstYearText = $item['is_first_year'] ? '<fg=cyan;options=bold>Sim</>' : 'Não';

            return [
                $item['coddis'],
                $item['codtur'],
                $item['sala'],
                $item['vagas_sala'],
                $item['inscritos'],
                $firstYearText, // Adiciona a nova coluna na saída da tabela
                "<fg={$style}>{$diferenca}</>",
            ];
        })->values()->toArray();

        $headers = [
            'Cód. Disciplina',
            'Turma',
            'Sala Alocada',
            'Vagas na Sala',
            'Inscritos (est.)',
            'Obrig. 1º Ano', // Novo cabeçalho
            'Sobra de Vagas',
        ];

        $this->table($headers, $tableRows);

        $this->line('');
        $this->info('Relatório gerado com sucesso.');

        return Command::SUCCESS;
    }
}