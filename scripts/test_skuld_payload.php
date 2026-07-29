<?php

/**
 * Diagnóstico: construção do payload do solver com predições reais do Skuld.
 *
 * Rode dentro do container da aplicação (precisa de DB + rede para o Skuld):
 *
 *   docker exec alocacao-app php -d max_execution_time=180 \
 *     scripts/test_skuld_payload.php
 *
 * O que valida:
 *  1. Consulta o endpoint /api/{version}/predict do Skuld com o semestre atual.
 *  2. Constrói o payload base (sem Skuld) e o payload com Skuld.
 *  3. Confirma que a ESTRUTURA é idêntica (mesmas chaves, mesma ordem).
 *  4. Lista os grupos cujo `demand` mudou e quantas predições casaram.
 *  5. Garante que NENHUM campo além de `demand` foi alterado.
 *
 * Observação: o mock do HistoricalEnrollmentService ignora o Replicado para
 * isolar o teste na substituição do estmtr pelo Skuld (lento via Sybase).
 */

$localBase = dirname(__DIR__);
$base = is_dir($localBase . '/vendor') ? $localBase : '/var/www';

require $base . '/vendor/autoload.php';
$app = require_once $base . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\SchoolTerm;
use App\Services\RoomAllocationPayloadBuilder;
use App\Services\SkuldPredictionService;

function out(string $s): void { echo $s . "\n"; @ob_flush(); flush(); }

$term = SchoolTerm::getLatest();
out("Termo: {$term->id} | {$term->year}/{$term->period}");

// 1. Predições reais do Skuld (pode levar ~40s)
out("Buscando predicoes Skuld (endpoint sincrono, aguarde)...");
$skuld = new SkuldPredictionService();
$predicoes = $skuld->predict($term);
out("Predicoes Skuld: " . count($predicoes));

if (empty($predicoes)) {
    out("AVISO: nenhuma predicao obtida (timeout/offline). Ajuste SKULD_TIMEOUT.");
}

// 2. Salas disponíveis (todas)
$roomIds = App\Models\Room::pluck('id')->all();
out("Salas: " . count($roomIds));

// 3. Mock do HistoricalEnrollmentService que retorna o estmtr puro sem
//    consultar o Replicado, isolando o teste na substituicao do Skuld.
$mockHist = new class(['historical_estimation_method' => 'none']) extends App\Services\HistoricalEnrollmentService {
    public function calculateAdjustedDemand($schoolClass): array
    {
        return ['demand' => $schoolClass->estmtr, 'applied' => false, 'metadata' => null];
    }
};

// 4. Payload SEM Skuld (baseline)
out("Construindo baseline (sem Skuld)...");
$baseline = (new RoomAllocationPayloadBuilder($mockHist))->build($term, $roomIds, []);
out("--- BASELINE (sem Skuld) ---");
out("Grupos: " . count($baseline['groups']));
out("Chaves do payload: " . implode(',', array_keys($baseline)));
out("Chaves do 1o grupo: " . implode(',', array_keys($baseline['groups'][0])));

// 5. Payload COM Skuld
out("Construindo com Skuld...");
$comSkuld = (new RoomAllocationPayloadBuilder($mockHist))->build($term, $roomIds, [], $predicoes);
out("--- COM SKULD ---");
out("Grupos: " . count($comSkuld['groups']));
out("Chaves do payload: " . implode(',', array_keys($comSkuld)));
out("Chaves do 1o grupo: " . implode(',', array_keys($comSkuld['groups'][0])));

// 6. Compara estrutura — chaves idênticas?
$estruturaIgual = array_keys($baseline['groups'][0]) === array_keys($comSkuld['groups'][0]);
out("");
out("Estrutura identica (ordem de chaves por grupo): " . ($estruturaIgual ? 'SIM' : 'NAO'));
$estruturaPayload = array_keys($baseline) === array_keys($comSkuld);
out("Estrutura payload raiz identica: " . ($estruturaPayload ? 'SIM' : 'NAO'));

// 7. Compara demand por grupo
$mudancas = 0;
$exemplos = [];
foreach ($baseline['groups'] as $i => $gBaseline) {
    $gSkuld = $comSkuld['groups'][$i];
    if ($gBaseline['demand'] !== $gSkuld['demand']) {
        $mudancas++;
        if (count($exemplos) < 8) {
            $exemplos[] = sprintf(
                "  [%s] %s | %s: baseline=%d -> skuld=%d",
                $gBaseline['id'], $gBaseline['coddis'], $gBaseline['codtur'],
                $gBaseline['demand'], $gSkuld['demand']
            );
        }
    }
}
out("");
out("Grupos com demand alterado pelo Skuld: {$mudancas} de " . count($baseline['groups']));
if ($mudancas > 0) {
    out("Exemplos:");
    foreach ($exemplos as $ex) out($ex);
}

// 8. Verifica campos nao-demand idênticos
$diferencaCampos = 0;
foreach ($baseline['groups'] as $i => $gBaseline) {
    $gSkuld = $comSkuld['groups'][$i];
    foreach ($gBaseline as $k => $v) {
        if ($k === 'demand') continue;
        if ($v !== $gSkuld[$k]) $diferencaCampos++;
    }
}
out("");
out("Diferencas em campos nao-demand: {$diferencaCampos} (esperado 0)");

// 9. Casamento de predicoes
$casamentos = 0;
$amostra = [];
foreach ($comSkuld['groups'] as $g) {
    $key = "{$g['coddis']}|{$g['codtur']}";
    if (isset($predicoes[$key])) {
        $casamentos++;
        if (count($amostra) < 5) {
            $amostra[] = "{$key}: skuld_estmtr={$predicoes[$key]} demand_grupo={$g['demand']}";
        }
    }
}
out("");
out("Predicoes do Skuld que casaram com coddis|codtur de grupos: {$casamentos}");
foreach ($amostra as $a) out("  $a");

out("");
out("=== TESTE CONCLUIDO ===");