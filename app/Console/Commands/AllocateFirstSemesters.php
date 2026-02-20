<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\SchoolTerm;
use App\Models\SchoolClass;
use App\Models\CourseInformation;
use App\Models\Room;

class AllocateFirstSemesters extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'alocacao:allocate-first-semesters 
                            {--dry-run : Apenas simula a alocação sem salvar alterações}
                            {--force : Ignora a confirmação antes de executar}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Aloca turmas obrigatórias do 1º semestre nas mesmas salas do ano passado';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Iniciando alocação automática de 1º semestres...');

        $currentTerm = SchoolTerm::getLatest();
        if (!$currentTerm) {
            $this->error('Nenhum período letivo encontrado.');
            return 1;
        }

        $previousTerm = SchoolTerm::where('year', $currentTerm->year - 1)
                                  ->where('period', $currentTerm->period)
                                  ->first();

        if (!$previousTerm) {
            $this->error("Período letivo correspondente do ano anterior ({$currentTerm->period} de " . ($currentTerm->year - 1) . ") não encontrado.");
            return 1;
        }

        $this->info("Período Atual: {$currentTerm->period} de {$currentTerm->year}");
        $this->info("Período Anterior: {$previousTerm->period} de {$previousTerm->year}");

        // Filter mandatory 1st semester classes in current term
        $classesToAllocate = SchoolClass::where('school_term_id', $currentTerm->id)
            ->where('tiptur', 'Graduação')
            ->whereHas('courseinformations', function ($query) {
                $query->where('numsemidl', 1)
                      ->where('tipobg', 'O');
            })
            ->get();

        if ($classesToAllocate->isEmpty()) {
            $this->warn('Nenhuma turma obrigatória de 1º semestre encontrada para alocar.');
            return 0;
        }

        $this->info("Encontradas " . $classesToAllocate->count() . " turmas para processar.");

        if (!$this->option('dry-run') && !$this->option('force')) {
            if (!$this->confirm('Deseja prosseguir com a alocação?')) {
                $this->info('Operação cancelada.');
                return 0;
            }
        }

        $allocatedCount = 0;
        $notFoundCount = 0;
        $alreadyAllocatedCount = 0;

        $bar = $this->output->createProgressBar($classesToAllocate->count());
        $bar->start();

        foreach ($classesToAllocate as $class) {
            if ($class->room_id) {
                $alreadyAllocatedCount++;
                $bar->advance();
                continue;
            }

            // Tenta encontrar a turma correspondente no ano passado
            // Mesma disciplina e mesmo final da turma (ex: ...45, ...48)
            $suffix = substr($class->codtur, -2);
            
            $previousClass = SchoolClass::where('school_term_id', $previousTerm->id)
                ->where('coddis', $class->coddis)
                ->where('codtur', 'like', "%$suffix")
                ->whereNotNull('room_id')
                ->first();

            if ($previousClass) {
                if (!$this->option('dry-run')) {
                    $class->room_id = $previousClass->room_id;
                    $class->save();
                }
                $allocatedCount++;
                $this->line("\n[OK] {$class->coddis} (T.{$class->codtur}) -> {$previousClass->room->nome}");
            } else {
                $notFoundCount++;
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info('Resumo da Operação:');
        $this->table(
            ['Métrica', 'Quantidade'],
            [
                ['Total processado', $classesToAllocate->count()],
                ['Novas alocações', $allocatedCount],
                ['Turmas já alocadas', $alreadyAllocatedCount],
                ['Salas não encontradas no ano passado', $notFoundCount],
            ]
        );

        if ($this->option('dry-run')) {
            $this->warn('⚠️  Nota: Executado em modo DRY-RUN. Nenhuma alteração foi salva no banco de dados.');
        }

        return 0;
    }
}
