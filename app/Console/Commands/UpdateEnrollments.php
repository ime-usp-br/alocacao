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
    protected $signature = 'schoolclass:update-enrollments';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Atualiza o número estimado de alunos inscritos para todas as turmas do semestre mais recente.';

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

        $schoolterm = SchoolTerm::getLatest();

        if (!$schoolterm) {
            $this->error('Nenhum período letivo encontrado. Por favor, cadastre um período primeiro.');
            return 1; // Retorna um código de erro
        }

        $this->info("Período letivo encontrado: {$schoolterm->period} de {$schoolterm->year}");

        $schoolClasses = SchoolClass::whereBelongsTo($schoolterm)->get();

        if ($schoolClasses->isEmpty()) {
            $this->warn('Nenhuma turma encontrada para o período letivo mais recente. Nada a fazer.');
            return 0;
        }

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
        $this->newLine(2); // Adiciona duas linhas em branco para espaçamento

        $this->info('Atualização do número de inscritos concluída com sucesso para ' . $schoolClasses->count() . ' turmas.');

        return 0; // Retorna sucesso
    }
}