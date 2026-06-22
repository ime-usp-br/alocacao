<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\SchoolTerm;
use App\Models\SchoolClass;

class UpdateEnrollments extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'schoolclass:update-enrollments
                            {--all-semesters : Processa todos os períodos letivos, não apenas o mais recente}
                            {--tipo= : Filtra turmas pelo tipo (ex: "Pós Graduação" ou "Graduação")}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Atualiza o número estimado de alunos inscritos para as turmas.';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Iniciando a atualização do número de inscritos...');

        $allSemesters = $this->option('all-semesters');
        $tipo = $this->option('tipo');

        if ($allSemesters) {
            $schoolTerms = SchoolTerm::all();
        } else {
            $schoolterm = SchoolTerm::getLatest();

            if (!$schoolterm) {
                $this->error('Nenhum período letivo encontrado. Por favor, cadastre um período primeiro.');
                return 1;
            }

            $schoolTerms = collect([$schoolterm]);
        }

        if ($schoolTerms->isEmpty()) {
            $this->error('Nenhum período letivo encontrado. Por favor, cadastre um período primeiro.');
            return 1;
        }

        $query = SchoolClass::whereIn('school_term_id', $schoolTerms->pluck('id'));

        if ($tipo) {
            $query->where('tiptur', $tipo);
        }

        $schoolClasses = $query->get();

        if ($schoolClasses->isEmpty()) {
            $this->warn('Nenhuma turma encontrada para os critérios informados. Nada a fazer.');
            return 0;
        }

        $termInfo = $allSemesters
            ? 'todos os períodos letivos'
            : $schoolTerms->first()->period . ' de ' . $schoolTerms->first()->year;

        $tipoInfo = $tipo ? " (tipo: {$tipo})" : '';

        $this->info("Período(s) letivo(s): {$termInfo}{$tipoInfo}");
        $this->info($schoolClasses->count() . ' turma(s) serão processadas.');

        $progressBar = $this->output->createProgressBar($schoolClasses->count());
        $progressBar->start();

        foreach ($schoolClasses as $schoolClass) {
            try {
                $schoolClass->calcEstimadedEnrollment();
                $schoolClass->save();
            } catch (\Exception $e) {
                $this->error("Falha ao atualizar a turma {$schoolClass->codtur} da disciplina {$schoolClass->coddis}: " . $e->getMessage());
            }
            $progressBar->advance();
        }

        $progressBar->finish();
        $this->newLine(2);

        $this->info('Atualização do número de inscritos concluída com sucesso para ' . $schoolClasses->count() . ' turmas.');

        return 0;
    }
}