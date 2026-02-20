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
            if (!$this->confirm('Deseja prosseguir com a alocação padrão (apenas turmas sem sala)?')) {
                $this->info('Operação cancelada.');
                return 0;
            }
        }

        $allocatedCount = 0;
        $movedCount = 0;
        $evictedCount = 0;
        $notFoundCount = 0;
        $alreadyCorrectCount = 0;

        $bar = $this->output->createProgressBar($classesToAllocate->count());
        $bar->start();

        foreach ($classesToAllocate as $class) {
            // Determine a turma "alocável" (se for fusion, é o master)
            $targetClass = $class->fusion_id ? $class->fusion->master : $class;

            // Tenta encontrar a turma correspondente no ano passado
            $suffix = substr($class->codtur, -2);
            $previousClass = SchoolClass::where('school_term_id', $previousTerm->id)
                ->where('coddis', $class->coddis)
                ->where('codtur', 'like', "%$suffix")
                ->whereNotNull('room_id')
                ->first();

            if ($previousClass) {
                $targetRoom = $previousClass->room;

                // Se já estiver na sala correta, nada a fazer
                if ($targetClass->room_id == $targetRoom->id) {
                    $alreadyCorrectCount++;
                    $bar->advance();
                    continue;
                }

                // Se estiver em outra sala e não for --force, pula
                if ($targetClass->room_id && !$this->option('force')) {
                    $bar->advance();
                    continue;
                }

                // Lógica de alocação/movimentação
                if ($this->option('force') || !$targetClass->room_id) {
                    $actionType = $targetClass->room_id ? 'MOVED' : 'ALLOCATED';
                    
                    // Se houver conflito na sala de destino, identifica/desocupa
                    $conflictingMasters = SchoolClass::where('school_term_id', $currentTerm->id)
                        ->where('room_id', $targetRoom->id)
                        ->get();

                    foreach ($conflictingMasters as $masterX) {
                        if ($targetClass->id != $masterX->id && $targetClass->isInConflict($masterX)) {
                            // Se masterX estiver em fusion e for o mesmo fusion do targetClass, não despeja
                            if ($targetClass->fusion_id && $targetClass->fusion_id == $masterX->fusion_id) {
                                continue;
                            }
                            
                            if (!$this->option('dry-run')) {
                                $masterX->room_id = null;
                                $masterX->save();
                            }
                            $evictedCount++;
                            $this->line("\n[EVICT] {$masterX->coddis} (T.{$masterX->codtur}) removida de {$targetRoom->nome} por conflito.");
                        }
                    }

                    if (!$this->option('dry-run')) {
                        $targetClass->room_id = $targetRoom->id;
                        $targetClass->save();
                    }

                    if ($actionType == 'MOVED') $movedCount++; else $allocatedCount++;
                    $this->line("\n[$actionType] {$class->coddis} (T.{$class->codtur}) -> {$targetRoom->nome}");
                }
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
                ['Turmas movidas de sala', $movedCount],
                ['Turmas desalojadas (conflito)', $evictedCount],
                ['Turmas já na sala correta', $alreadyCorrectCount],
                ['Salas não encontradas no ano passado', $notFoundCount],
            ]
        );

        if ($this->option('dry-run')) {
            $this->warn('⚠️  Nota: Executado em modo DRY-RUN. Nenhuma alteração foi salva no banco de dados.');
        }

        return 0;
    }
}
