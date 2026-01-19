<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\SchoolTerm;
use App\Models\SchoolClass;
use App\Models\Instructor;
use App\Models\ClassSchedule;
use App\Models\Priority;
use App\Models\Room;
use App\Models\CourseInformation;
use App\Models\Fusion;
use App\Models\Course;

class UpdateAllocations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'alocacao:update';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Atualiza turmas, horários e professores interagindo com o sistema Replicado (espelhado de ProcessImportSchoolClasses)';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Iniciando atualização de alocações via Replicado...');

        // 1. Obter o período letivo mais recente
        $schoolterm = SchoolTerm::getLatest();

        if (!$schoolterm) {
            $this->error('Nenhum período letivo encontrado. Cadastre um período antes de rodar este comando.');
            return 1;
        }

        $this->line("Período Letivo: <comment>{$schoolterm->period} de {$schoolterm->year}</comment>");

        // 2. Buscar turmas do Replicado (Fase 1: Coleta de Dados)
        $this->info('Fase 1/3: Buscando dados do Replicado...');
        
        $progressBarFetch = null;

        $onInit = function ($total) use (&$progressBarFetch) {
            $this->line("Total estimado de pacotes de dados: {$total}");
            $progressBarFetch = $this->output->createProgressBar($total);
            $progressBarFetch->start();
        };

        $onProgress = function () use (&$progressBarFetch) {
            if ($progressBarFetch) {
                $progressBarFetch->advance();
            }
        };

        // Chama o método modificado com callbacks
        $turmas = SchoolClass::getFromReplicadoBySchoolTerm($schoolterm, $onProgress, $onInit);

        if ($progressBarFetch) {
            $progressBarFetch->finish();
        }
        $this->newLine(2);
        
        $countTurmas = count($turmas);
        $this->info("Dados carregados. Encontradas {$countTurmas} turmas potenciais.");


        // 3. Processamento Principal (Fase 2: Processamento e Salvamento)
        $this->info('Fase 2/3: Processando e Salvando Turmas...');

        $progressBarProcess = $this->output->createProgressBar($countTurmas);
        $progressBarProcess->start();

        foreach ($turmas as $turma) {
            try {
                // Filtros de exclusão (mesma lógica do Job)
                if (($turma['tiptur'] == "Pós Graduação") or 
                    ($turma['tiptur'] == "Graduação" and substr($turma["codtur"], -2, 2) >= "40") or
                    ($turma['coddis'] == "MAT0112" and substr($turma["codtur"], -2, 2) == "34") or
                    ($turma['coddis'] == "MAT0111" and substr($turma["codtur"], -2, 2) == "34") or
                    ($turma['coddis'] == "MAT0121" and substr($turma["codtur"], -2, 2) == "34") or
                    ($turma['tiptur'] == "Graduação" and $turma["coddis"] == "MAE0116") or
                    ($turma["externa"])) {
                    
                    if ($turma['class_schedules']) {
                        $schoolclass = SchoolClass::where(array_intersect_key($turma, array_flip(array('codtur', 'coddis'))))->first();
            
                        if (!$schoolclass) {
                            // Criar nova turma
                            $schoolclass = new SchoolClass;
                            $schoolclass->fill($turma);
                            $schoolclass->save();
                    
                            foreach ($turma['instructors'] as $instructor) {
                                if ($instructor) {
                                    $docente = Instructor::getFromReplicadoByCodpes($instructor["codpes"]);
                                    $schoolclass->instructors()->attach(Instructor::updateOrCreate(
                                        ["nompes" => $docente["nompes"], "codpes" => $docente["codpes"]],
                                        ["codema" => $docente["codema"]]
                                    ));
                                }
                            }
                
                            foreach ($turma['class_schedules'] as $classSchedule) {
                                $schoolclass->classschedules()->attach(ClassSchedule::firstOrCreate($classSchedule));
                            }

                            // Prioridades (Job logic)
                            $priorities = Priority::$priorities_by_course;

                            if (in_array($schoolclass->coddis, array_keys($priorities))) {
                                foreach ($priorities[$schoolclass->coddis] as $codtur => $salas) {
                                    if ($codtur == substr($schoolclass->codtur, -2, 2) and $schoolclass->tiptur == "Graduação") {
                                        foreach ($salas as $room_name => $priority) {
                                            if ($priority > 20) {
                                                $room = Room::where("nome", $room_name)->first();
                                                if ($room) {
                                                    Priority::updateOrCreate(
                                                        ["room_id" => $room->id, "school_class_id" => $schoolclass->id],
                                                        ["priority" => $priority]
                                                    );
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        } else {
                            // Atualizar turma existente
                            $schoolclass->instructors()->detach();
                            foreach ($turma['instructors'] as $instructor) {
                                if ($instructor) {
                                    $docente = Instructor::getFromReplicadoByCodpes($instructor["codpes"]);
                                    $schoolclass->instructors()->attach(Instructor::updateOrCreate(
                                        ["nompes" => $docente["nompes"], "codpes" => $docente["codpes"]],
                                        ["codema" => $docente["codema"]]
                                    ));
                                }
                            }

                            $schoolclass->classschedules()->detach();            
                            foreach ($turma['class_schedules'] as $classSchedule) {
                                $schoolclass->classschedules()->attach(ClassSchedule::firstOrCreate($classSchedule));
                            }

                            $schoolclass->school_term_id = $schoolterm->id;
                            $schoolclass->save();
                        }

                        // Course Information
                        foreach (CourseInformation::getFromReplicadoBySchoolClass($schoolclass) as $info) {
                            if (in_array($info["nomcur"], Course::all()->pluck("nomcur")->toArray())) {
                                $ci = CourseInformation::firstOrCreate($info);
                                if (!$ci->schoolclasses->contains($schoolclass->id)) {
                                    $ci->schoolclasses()->save($schoolclass);
                                }
                            }
                        }

                        // Estimativa de matriculas
                        $schoolclass->calcEstimadedEnrollment();
                        $schoolclass->save();

                        // Fusão
                        $schoolclass->searchForFusion();
                    }
                }
            } catch (\Exception $e) {
                // Logar o erro mas não parar o progresso
                $this->newLine();
                $this->error("Erro ao processar turma {$turma['codtur']}: " . $e->getMessage());
            }

            $progressBarProcess->advance();
        }

        $progressBarProcess->finish();
        $this->newLine(2);


        // 4. Pós-processamento (Fase 3: Limpeza e Consolidação)
        $this->info('Fase 3/3: Consolidação e Limpeza...');

        // Parte 3.1: Fazer merge dos classSchedules duplicados
        $schoolClassesList = SchoolClass::whereBelongsTo($schoolterm)->get();
        
        $progressBarPost = $this->output->createProgressBar($schoolClassesList->count());
        $progressBarPost->start();

        foreach ($schoolClassesList as $schoolclass) {
            try {
                if (count($schoolclass->classschedules) > 1 and count($schoolclass->classschedules->pluck("diasmnocp")->unique()->toArray()) == 1) {
                    $schedules = $schoolclass->classschedules;
                    $schoolclass->classschedules()->detach();
                    
                    // Nota: O método firstOrCreate pode falhar se dados forem nulos, adicionar proteção se necessário
                    // Mas mantendo fiel ao Job original:
                    $schoolclass->classschedules()->attach(ClassSchedule::firstOrCreate([
                        "diasmnocp" => $schedules->pluck("diasmnocp")[0],
                        "horent" => $schedules->pluck("horent")->min(),
                        "horsai" => $schedules->pluck("horsai")->max()
                    ]));
                }
            } catch (\Exception $e) {
                // Silencioso ou logar se necessário
            }
            $progressBarPost->advance();
        }
        $progressBarPost->finish();
        $this->newLine();

        // Parte 3.2: Course Information Alternativo
        $this->info('Atualizando Informações de Curso Alternativas...');
        
        $schoolClassesGrad = SchoolClass::whereBelongsTo($schoolterm)->where("tiptur", "Graduação")->get();
        // Filtro complexo do Job original
        $schoolClassesGradFiltered = $schoolClassesGrad->filter(function($schoolclass) {
            if (count(SchoolClass::whereBelongsTo($schoolclass->schoolterm)->where("coddis", $schoolclass->coddis)->get()) == 1) {
                return true;
            }
            return false;
        });

        $progressBarAlt = $this->output->createProgressBar($schoolClassesGradFiltered->count());
        $progressBarAlt->start();

        foreach ($schoolClassesGradFiltered as $schoolclass) {
            try {
                $schoolclass->courseinformations()->detach();
                foreach (CourseInformation::getFromReplicadoBySchoolClassAlternative($schoolclass) as $info) {
                    CourseInformation::firstOrCreate($info)->schoolclasses()->save($schoolclass);
                }
            } catch (\Exception $e) {
                // Ignorar
            }
            $progressBarAlt->advance();
        }
        $progressBarAlt->finish();
        $this->newLine();

        // Parte 3.3: Linkar turmas órfãs de CourseInformation
        $this->info('Vinculando turmas sem informações de curso...');
        
        // Logica complexa do job para montar $cis
        // Job: foreach(SchoolClass... whereDoesntHave("courseinformations")...
        
        $orphanedClasses = SchoolClass::whereBelongsTo($schoolterm)
            ->where("tiptur", "Graduação")
            ->whereDoesntHave("courseinformations")
            ->get();
            
        // Filtrar aquelas onde NÃO existe nenhuma outra turma da mesma disciplina que TENHA courseInfo processado
        // O Job faz isso iterativamente.
        
        $cisToProcess = [];
        foreach ($orphanedClasses as $schoolclass) {
             if (!SchoolClass::whereBelongsTo($schoolterm)
                    ->where("coddis", $schoolclass->coddis)
                    ->whereHas("courseinformations")
                    ->exists()) {
                
                try {
                    $infos = CourseInformation::getFromReplicadoBySchoolClassAlternative($schoolclass);
                    $cisToProcess[] = ["schoolclass" => $schoolclass, "infos" => $infos];
                } catch (\Exception $e) {
                    continue;
                }
            }
        }

        if (count($cisToProcess) > 0) {
            $progressBarOrphans = $this->output->createProgressBar(count($cisToProcess));
            $progressBarOrphans->start();

            foreach ($cisToProcess as $item) {
                try {
                    foreach ($item["infos"] as $info) {
                        CourseInformation::firstOrCreate($info)->schoolclasses()->save($item["schoolclass"]);
                    }
                } catch (\Exception $e) {
                    // Ignorar
                }
                $progressBarOrphans->advance();
            }
            $progressBarOrphans->finish();
            $this->newLine();
        } else {
             $this->info('Nenhuma vinculação adicional necessária.');
        }
        
        $this->newLine();
        $this->info('✅ Atualização concluída com sucesso!');

        return 0;
    }
}
