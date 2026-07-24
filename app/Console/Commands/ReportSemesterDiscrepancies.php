<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\SchoolTerm;
use App\Models\SchoolClass;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ReportSemesterDiscrepancies extends Command
{
    /**
     * O nome e a assinatura do comando do console.
     *
     * @var string
     */
    protected $signature = 'semester:report-discrepancies';

    /**
     * A descrição do comando do console.
     *
     * @var string
     */
    protected $description = 'Relata disciplinas que mudaram de status (Interna/Externa) entre o semestre atual e o anterior e turmas históricas ausentes (oferecidas no ano base e não cadastradas no semestre atual).';

    /**
     * Executa o comando do console.
     *
     * @return int
     */
    public function handle()
    {
        $currentSchoolTerm = SchoolTerm::getLatest();

        if (!$currentSchoolTerm) {
            $this->error('Nenhum período letivo atual encontrado. Por favor, cadastre um período primeiro.');
            return Command::FAILURE;
        }

        $previousSchoolTerm = SchoolTerm::where('year', $currentSchoolTerm->year - 1)
            ->where('period', $currentSchoolTerm->period)
            ->first();

        if (!$previousSchoolTerm) {
            $this->error("Não foi encontrado o período letivo correspondente do ano anterior ({$currentSchoolTerm->period} de " . ($currentSchoolTerm->year - 1) . ").");
            return Command::FAILURE;
        }

        $this->displayHeader("Análise de Discrepâncias: {$currentSchoolTerm->period} de {$currentSchoolTerm->year} vs {$previousSchoolTerm->period} de {$previousSchoolTerm->year}");
        
        $progressBar = $this->output->createProgressBar(2);
        $progressBar->setFormat("Buscando dados: [%bar%] %percent:3s%%");
        $progressBar->start();
        
        $currentClasses = SchoolClass::whereBelongsTo($currentSchoolTerm)->get();
        $progressBar->advance();
        
        $previousClasses = SchoolClass::whereBelongsTo($previousSchoolTerm)->get();
        $progressBar->finish();
        $this->newLine(2);
        
        $statusChanges = $this->findStatusChanges($currentClasses, $previousClasses);
        $missingClasses = $this->findMissingClasses($currentClasses, $previousClasses);

        $this->renderStatusChanges($statusChanges);
        $this->renderMissingClasses($missingClasses, $previousSchoolTerm, $currentSchoolTerm);

        return Command::SUCCESS;
    }

    /**
     * Encontra turmas oferecidas no ano base (semestre anterior) que não têm correspondente no semestre atual.
     */
    private function findMissingClasses(Collection $currentClasses, Collection $previousClasses): Collection
    {
        $currentClassesByKey = $currentClasses->keyBy(fn($class) => $this->getSchoolClassKey($class));

        return $previousClasses
            ->where('externa', false)
            ->where('tiptur', '!=', 'Pós Graduação')
            ->reject(fn($previous) => str_contains($previous->coddis, '*'))
            ->reject(fn($previous) => str_contains($previous->codtur, '*'))
            ->reject(fn($previous) => $currentClassesByKey->has($this->getSchoolClassKey($previous)))
            ->sortBy([['coddis', 'asc'], ['codtur', 'asc']])
            ->values();
    }

    /**
     * Encontra todas as turmas que mudaram o status 'externa'.
     */
    private function findStatusChanges(Collection $currentClasses, Collection $previousClasses): Collection
    {
        $currentClassesByKey = $currentClasses->keyBy(fn($class) => $this->getSchoolClassKey($class));
        $previousClassesByKey = $previousClasses->keyBy(fn($class) => $this->getSchoolClassKey($class));
        
        $changes = [];

        foreach ($currentClassesByKey as $key => $current) {
            if ($previousClassesByKey->has($key)) {
                $previous = $previousClassesByKey[$key];
                
                if ($current->externa != $previous->externa) {
                    $changes[] = [
                        'class' => $current,
                        'from' => $previous->externa ? 'Externa' : 'Interna',
                        'to' => $current->externa ? 'Externa' : 'Interna'
                    ];
                }
            }
        }
        
        return collect($changes);
    }
    
    /**
     * Renderiza a seção de mudanças de status (Interna/Externa).
     */
    private function renderStatusChanges(Collection $changes)
    {
        $this->line('');
        $this->warn("[ SEÇÃO 1: MUDANÇAS DE STATUS IDENTIFICADAS ({$changes->count()}) ]");
        $this->line(str_repeat('-', 50));

        if ($changes->isEmpty()) {
            $this->info('Nenhuma disciplina mudou de status entre Interna e Externa.');
            return;
        }

        $headers = ['Código Disciplina', 'Turma', 'Nome da Disciplina', 'Status Anterior', 'Status Atual'];
        $rows = $changes->map(fn($change) => [
            $change['class']->coddis,
            $change['class']->codtur,
            Str::limit($change['class']->nomdis, 50),
            $change['from'],
            $change['to'],
        ]);

        $this->table($headers, $rows);
    }

    /**
     * Renderiza a seção de turmas históricas ausentes (oferecidas no ano base e não cadastradas no atual).
     */
    private function renderMissingClasses(Collection $missing, SchoolTerm $previousSchoolTerm, SchoolTerm $currentSchoolTerm)
    {
        $subtitle = "Oferecidas em {$previousSchoolTerm->year}/" . ($previousSchoolTerm->period === '1° Semestre' ? '1' : '2') 
            . " e não criadas em {$currentSchoolTerm->year}/" . ($currentSchoolTerm->period === '1° Semestre' ? '1' : '2');

        $this->line('');
        $this->warn("[ SEÇÃO 2: TURMAS HISTÓRICAS AUSENTES ({$missing->count()}) ]");
        $this->line($subtitle);
        $this->line(str_repeat('-', 50));

        if ($missing->isEmpty()) {
            $this->info('Todas as turmas do ano base foram cadastradas no semestre atual.');
            return;
        }

        $headers = ['Disciplina', 'Turma', 'Nome da Disciplina', 'Inscritos no Ano Base'];
        $rows = $missing->map(fn($class) => [
            $class->coddis,
            substr($class->codtur, -2),
            Str::limit($class->nomdis, 50),
            $class->estmtr ?? '—',
        ]);

        $this->table($headers, $rows);

        $this->newLine();
        $this->line('-------------------------------------------------------------------');
        $this->info(sprintf(
            "Resumo: %d turmas ausentes no semestre atual comparadas ao ano base.",
            $missing->count()
        ));
    }

    // MÉTODOS AUXILIARES
    
    private function displayHeader(string $title): void
    {
        $this->info(str_repeat('=', strlen($title) + 4));
        $this->info("  " . $title);
        $this->info(str_repeat('=', strlen($title) + 4));
        $this->newLine();
    }
    
    private function getSchoolClassKey(SchoolClass $schoolClass): string
    {
        if ($schoolClass->tiptur === 'Pós Graduação') {
            return $schoolClass->coddis;
        }
        return $schoolClass->coddis . '-' . substr($schoolClass->codtur, -2);
    }
}