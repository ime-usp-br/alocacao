<?php

/**
 * Restaura o estado "Pré Alocação(turmas manuais)" do semestre 20262 e
 * realoca as turmas anuais (codtur com prefixo "20261") em suas salas
 * originais do "Último Estado Válido do primeiro semestre de 2026".
 *
 * Fluxo:
 *  1. Salva snapshot de segurança do estado atual (rollback).
 *  2. Restaura o estado "Pré Alocação(turmas manuais)" (id 33, termo 9) no
 *     banco E em memória — esta coleção em memória é a única fonte de verdade
 *     para a simulação (o script NÃO relê o banco depois disso).
 *  3. Para cada turma anual do termo 9, lê a sala do estado válido (id 2,
 *     termo 8) e SIMULA a alocação sobre o estado em memória, verificando
 *     conflito de horário contra todas as turmas (anuais ou não) que já
 *     estejam alocadas naquela sala, acumulando as anuais a cada passo.
 *  4. Se houver conflito, imprime todos (com dias/horários) no stdout e
 *     NÃO aplica a realocação dos anuais (o BD permanece no estado manual
 *     restaurado do passo 2); aborta.
 *  5. Se não houver conflito, aplica as alocações dos anuais no BD e salva um
 *     novo AllocationState com nome descritivo.
 *
 * Uso:
 *   php scripts/restore_annuals_from_20261.php [--dry-run]
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\AllocationState;
use App\Models\Room;
use App\Models\SchoolClass;
use App\Models\SchoolTerm;
use App\Services\AllocationStateService;
use Illuminate\Support\Facades\DB;

$dryRun = in_array('--dry-run', $argv, true);

$MANUAL_STATE_ID = 33;  // "Pré Alocação(turmas manuais)" — termo 9 (20262)
$VALID_STATE_ID  = 2;   // "Último Estado Válido do primeiro semestre de 2026" — termo 8 (20261)
$ANNUAL_PREFIX   = '20261';

/** Formata os horários de uma turma como "ter 17:30-19:10, qui 14:00-15:40". */
$formatSchedules = function (SchoolClass $class): string {
    $parts = [];
    foreach ($class->classschedules as $cs) {
        $parts[] = "{$cs->diasmnocp} {$cs->horent}-{$cs->horsai}";
    }
    sort($parts);
    return empty($parts) ? '(sem horário)' : implode(', ', $parts);
};

/** Resolve o "alvo" de escrita de sala de uma turma (mestre da fusão, se houver). */
$resolveTarget = function (SchoolClass $c): SchoolClass {
    if ($c->fusion_id && $c->fusion && $c->fusion->master) {
        return $c->fusion->master;
    }
    return $c;
};

echo "=== restore_annuals_from_20261.php ===\n";
echo "Modo: " . ($dryRun ? "DRY-RUN (sem gravar)" : "APLICAR") . "\n\n";

// --- 0. Resolve semestre e estados -------------------------------------------

$term = SchoolTerm::getLatest();
if ($term === null) {
    fwrite(STDERR, "ERRO: nenhum semestre letivo ativo encontrado.\n");
    exit(1);
}
echo "Semestre ativo: {$term->year}/{$term->period} (id={$term->id})\n";

$manualState = AllocationState::find($MANUAL_STATE_ID);
if ($manualState === null) {
    fwrite(STDERR, "ERRO: estado {$MANUAL_STATE_ID} (Pré Alocação turmas manuais) não encontrado.\n");
    exit(1);
}
$validState = AllocationState::find($VALID_STATE_ID);
if ($validState === null) {
    fwrite(STDERR, "ERRO: estado {$VALID_STATE_ID} (Último Estado Válido 20261) não encontrado.\n");
    exit(1);
}

echo "Estado manual: [{$manualState->name}] (id={$manualState->id}, termo={$manualState->school_term_id})\n";
echo "Estado válido: [{$validState->name}] (id={$validState->id}, termo={$validState->school_term_id})\n\n";

$rooms = Room::pluck('nome', 'id');

// --- 1. Snapshot de segurança do estado atual --------------------------------

echo "[1/6] Salvando snapshot de segurança do estado atual...\n";
$safetyName = 'Snapshot pré-restore_annuals - ' . now()->format('d/m/Y H:i:s');
if ($dryRun) {
    echo "  (dry-run) snapshot não persistido: {$safetyName}\n";
} else {
    $safety = AllocationStateService::capture($term, $safetyName);
    echo "  Snapshot salvo: id={$safety->id} — \"{$safetyName}\"\n";
    echo "  >>> Para reverter, use o restore deste estado (id={$safety->id}). <<<\n";
}

// --- 2. Restaura o estado "Pré Alocação(turmas manuais)" ---------------------
//
// Carregamos TODAS as turmas do termo (não-externas) UMA vez, com seus
// horários e fusões. Esta é a ÚNICA coleção usada daqui em diante — nenhum
// re-fetch do banco — garantindo que a simulação reflita exatamente o estado
// restaurado, inclusive em dry-run.

echo "\n[2/6] Restaurando estado [{$manualState->name}]...\n";
$manualAllocations = $manualState->allocations ?? [];
$allClasses = SchoolClass::whereBelongsTo($term)
    ->where('externa', false)
    ->with(['classschedules', 'fusion.master', 'fusion.schoolclasses'])
    ->orderBy('id')
    ->get();

// indexa por id e agrupa por target_id (mestre da fusão)
$classesByTarget = $allClasses->keyBy(fn ($c) => $c->id);

DB::beginTransaction();
try {
    $applied = 0;
    foreach ($manualAllocations as $classId => $roomId) {
        $sc = $classesByTarget->get($classId);
        if ($sc === null) {
            continue;
        }
        $target = $resolveTarget($sc);
        if ($target->room_id != $roomId) {
            $target->room_id = $roomId;
            if (! $dryRun) {
                $target->save();
            }
            $applied++;
        }
    }
    DB::commit();
    echo "  Estado manual restaurado ({$applied} turmas ajustadas).\n";
} catch (\Throwable $e) {
    DB::rollBack();
    fwrite(STDERR, "ERRO ao restaurar estado manual: " . $e->getMessage() . "\n");
    exit(1);
}

// --- 3. Coleta turmas anuais e salas-alvo do estado válido -------------------

echo "\n[3/6] Coletando turmas anuais (prefixo {$ANNUAL_PREFIX}) e salas-alvo...\n";

$validAllocations = $validState->allocations ?? [];

$annualClasses = $allClasses->filter(
    fn ($c) => str_starts_with($c->codtur, $ANNUAL_PREFIX)
)->sortBy(fn ($c) => $c->coddis . $c->codtur)->values();

if ($annualClasses->isEmpty()) {
    fwrite(STDERR, "ERRO: nenhuma turma anual (prefixo {$ANNUAL_PREFIX}) encontrada no termo {$term->id}.\n");
    exit(1);
}

$targets = []; // [target_id => ['class'=>, 'target'=>, 'room_id'=>, 'coddis'=>, 'codtur'=>]]
foreach ($annualClasses as $sc) {
    $target = $resolveTarget($sc);

    $validRoomId = $validAllocations[$sc->id] ?? null;
    if ($validRoomId === null && $sc->fusion_id && $sc->fusion) {
        // snapshot pode ter registrado a sala num filho/mestre da fusão
        foreach ($sc->fusion->schoolclasses as $child) {
            if (isset($validAllocations[$child->id]) && $validAllocations[$child->id] !== null) {
                $validRoomId = $validAllocations[$child->id];
                break;
            }
        }
    }

    if ($validRoomId === null) {
        echo "  AVISO: turma {$sc->coddis} {$sc->codtur} (id={$sc->id}) sem sala no estado válido; pulando.\n";
        continue;
    }

    $targets[$target->id] = [
        'class' => $sc,
        'target' => $target,
        'room_id' => (int) $validRoomId,
        'coddis' => $sc->coddis,
        'codtur' => $sc->codtur,
    ];
}

echo "  " . count($targets) . " turma(s) anual(is) com sala-alvo definida:\n";
foreach ($targets as $t) {
    $rn = $rooms[$t['room_id']] ?? ('#' . $t['room_id']);
    echo "    {$t['coddis']} {$t['codtur']} (id={$t['target']->id}) -> {$rn} (room_id={$t['room_id']})\n";
    echo "                    horários atuais: " . $formatSchedules($t['class']) . "\n";
}
echo "\n";

if (empty($targets)) {
    fwrite(STDERR, "ERRO: nenhuma turma anual pôde ser mapeada para uma sala do estado válido.\n");
    exit(1);
}

// --- 4. Simulação: detecta conflitos de horário ------------------------------
//
// Ocupantes por sala: construídos a partir do estado em memória (manual
// restaurado), excluindo as anuais (que serão inseridas incrementalmente).
// Para cada sala-alvo de uma anual, verificamos conflito contra TODOS os
// membros de cada grupo fundido ocupante (a fusão compartilha a sala, logo o
// horário combinado é a união dos membros).

echo "[4/6] Simulando alocação dos anuais e detectando conflitos...\n";

$annualTargetIds = array_keys($targets);

// grupos por target_id (mestre da fusão): coleção de classes que compartilham
// a mesma sala (para fusões, todos os filhos + mestre; para solo, só a turma).
$classesByTargetId = $allClasses
    ->groupBy(fn ($c) => $resolveTarget($c)->id);

// ocupação atual por sala, EXCLUINDO as anuais (que simulamos abaixo).
$roomOccupancy = []; // room_id => [target_id => true]
foreach ($allClasses as $c) {
    $target = $resolveTarget($c);
    if (in_array($target->id, $annualTargetIds, true)) {
        continue;
    }
    if ($target->room_id === null) {
        continue;
    }
    $roomOccupancy[$target->room_id][$target->id] = true;
}

$conflicts = [];

/** Checa a anual contra todos os membros do grupo ocupante (target). */
$checkAgainstTarget = function (SchoolClass $annualCls, int $targetId) use ($classesByTargetId): ?SchoolClass {
    $members = $classesByTargetId->get($targetId, collect());
    foreach ($members as $member) {
        // mesmo grupo de fusão: partilham sala por design, não é conflito
        if ($annualCls->fusion_id && $member->fusion_id
            && $annualCls->fusion_id === $member->fusion_id) {
            continue;
        }
        if ($annualCls->isInConflict($member)) {
            return $member;
        }
    }
    return null;
};

$ordered = collect($targets)->sortBy(fn ($t) => $t['coddis'] . $t['codtur']);
foreach ($ordered as $t) {
    $annualTargetId = $t['target']->id;
    $roomId = $t['room_id'];
    $annualClass = $t['class'];

    $occupants = $roomOccupancy[$roomId] ?? [];
    foreach (array_keys($occupants) as $otherTargetId) {
        if ($otherTargetId === $annualTargetId) {
            continue;
        }
        $otherMember = $checkAgainstTarget($annualClass, (int) $otherTargetId);
        if ($otherMember !== null) {
            $rn = $rooms[$roomId] ?? ('#' . $roomId);
            $conflicts[] = [
                'annual_label' => "{$annualClass->coddis} {$annualClass->codtur} (id={$annualClass->id})",
                'annual_sched' => $formatSchedules($annualClass),
                'other_label'  => "{$otherMember->coddis} {$otherMember->codtur} (id={$otherMember->id})"
                    . " [grupo id={$otherTargetId}]",
                'other_sched'  => $formatSchedules($otherMember),
                'room' => "{$rn} (room_id={$roomId})",
            ];
        }
    }
    // adiciona a anual à ocupação da sala para checar contra as próximas anuais
    $roomOccupancy[$roomId][$annualTargetId] = true;
}

if (! empty($conflicts)) {
    echo "\n" . count($conflicts) . " CONFLITO(S) DETECTADO(S) — aplicação ABORTADA.\n";
    echo "O BD permanece no estado manual restaurado (passo 2).\n\n";
    $i = 1;
    foreach ($conflicts as $c) {
        echo "  [{$i}] Sala {$c['room']}\n";
        echo "      Turma anual:  {$c['annual_label']}\n";
        echo "                    horários: {$c['annual_sched']}\n";
        echo "      Conflita com: {$c['other_label']}\n";
        echo "                    horários: {$c['other_sched']}\n";
        $i++;
    }
    echo "\nNenhum alteração nos anuais foi aplicada. Reverta via snapshot de segurança se necessário.\n";
    exit(2);
}

echo "  Nenhum conflito detectado.\n\n";

// --- 5. Aplica as alocações e salva novo estado ------------------------------

echo "[5/6] Aplicando alocações dos anuais e salvando novo estado...\n";

DB::beginTransaction();
try {
    foreach ($ordered as $t) {
        $target = $t['target'];
        if ($target->room_id != $t['room_id']) {
            $target->room_id = $t['room_id'];
            if (! $dryRun) {
                $target->save();
            }
        }
    }

    $newName = 'Resultado do script restore_annuals_from_20261 - '
        . now()->format('d/m/Y H:i:s')
        . ($dryRun ? ' (DRY-RUN)' : '');
    if ($dryRun) {
        echo "  (dry-run) novo estado não persistido: {$newName}\n";
    } else {
        $newState = AllocationStateService::capture($term, $newName);
        echo "  Novo estado salvo: id={$newState->id} — \"{$newName}\"\n";
    }

    DB::commit();
} catch (\Throwable $e) {
    DB::rollBack();
    fwrite(STDERR, "ERRO ao aplicar alocações dos anuais: " . $e->getMessage() . "\n");
    exit(1);
}

// --- 6. Verificação final: confere manualidades + anuais no banco ------------
//
// Relê o estado atual do BD e compara com:
//   (a) o estado "Pré Alocação(turmas manuais)" — TODAS as suas turmas devem
//       estar na mesma sala gravada por aquele estado;
//   (b) as salas-alvo dos anuais (vindas do estado válido do 1º semestre).
// Em dry-run, compara contra o que *seria* gravado (já espelha Length em
// memória); em modo aplicar, compara contra o relêdo banco para garantir a
// persistência.

echo "\n[6/6] Verificação final no banco...\n";

$freshClasses = $dryRun
    ? null
    : SchoolClass::whereBelongsTo($term)
        ->with(['fusion.master', 'fusion.schoolclasses'])
        ->get()
        ->keyBy('id');

/** Resolve o room_id vigente de uma turma (alvo = mestre de fusão). */
$resolveCurrentRoom = function (int $classId) use ($dryRun, $freshClasses, $allClasses, $resolveTarget): ?int {
    if ($dryRun) {
        $sc = $allClasses->first(fn ($c) => (int) $c->id === $classId);
    } else {
        $sc = $freshClasses->get($classId);
    }
    if ($sc === null) {
        return null;
    }
    $target = $resolveTarget($sc);
    return $target->room_id !== null ? (int) $target->room_id : null;
};

$mismatches = [];

// (a) todas as turmas do estado manual devem estar nas salas que aquele
// estado definiu — EXCETO os anuais, que são intencionalmente realocados
// pelo passo 5 e conferidos separadamente no item (b).
$annualClassIds = [];
foreach ($targets as $t) {
    $annualClassIds[] = (int) $t['class']->id;
    // inclui também os filhos da fusão da anual (se houver), pois suas salas
    // são reatribuídas ao mestre.
    if ($t['class']->fusion_id && $t['class']->fusion) {
        foreach ($t['class']->fusion->schoolclasses as $child) {
            $annualClassIds[] = (int) $child->id;
        }
    }
}
$annualClassIds = array_values(array_unique($annualClassIds));

foreach ($manualAllocations as $classId => $expectedRoomId) {
    if (in_array((int) $classId, $annualClassIds, true)) {
        continue; // anual: conferido no item (b)
    }
    $expectedRoomId = $expectedRoomId !== null ? (int) $expectedRoomId : null;
    $currentRoomId = $resolveCurrentRoom((int) $classId);
    if ($currentRoomId !== $expectedRoomId) {
        $sc = $dryRun
            ? $allClasses->first(fn ($c) => (int) $c->id === (int) $classId)
            : ($freshClasses->get($classId) ?? $allClasses->first(fn ($c) => (int) $c->id === (int) $classId));
        $label = $sc ? "{$sc->coddis} {$sc->codtur}" : "id={$classId}";
        $exp = $expectedRoomId !== null ? ($rooms[$expectedRoomId] ?? ('#' . $expectedRoomId)) : '(sem sala)';
        $cur = $currentRoomId  !== null ? ($rooms[$currentRoomId]  ?? ('#' . $currentRoomId))  : '(sem sala)';
        $mismatches[] = "manual: {$label} (id={$classId}) esperado {$exp}, atual {$cur}";
    }
}

// (b) os anuais devem estar nas salas-alvo do estado válido.
foreach ($targets as $t) {
    $classId = $t['class']->id;
    $expectedRoomId = $t['room_id'];
    $currentRoomId = $resolveCurrentRoom($classId);
    if ($currentRoomId !== $expectedRoomId) {
        $label = "{$t['coddis']} {$t['codtur']}";
        $exp = $rooms[$expectedRoomId] ?? ('#' . $expectedRoomId);
        $cur = $currentRoomId !== null ? ($rooms[$currentRoomId] ?? ('#' . $currentRoomId)) : '(sem sala)';
        $mismatches[] = "anual: {$label} (id={$classId}) esperado {$exp}, atual {$cur}";
    }
}

$manualCount = count($manualAllocations);
$annualInManual = count(array_values(array_intersect($annualClassIds, array_keys($manualAllocations))));
$manualChecked = max(0, $manualCount - $annualInManual);
$annualCount = count($targets);
$totalChecked = $manualChecked + $annualCount;
$okCount = $totalChecked - count($mismatches);
echo "  Conferidas {$manualChecked} turma(s) do estado manual (excl. anuais realocados) + {$annualCount} anuais "
    . "= {$okCount}/{$totalChecked} nas salas esperadas.\n";

if (! empty($mismatches)) {
    echo "\n  *** " . count($mismatches) . " DIVERGÊNCIA(S) ***\n";
    foreach ($mismatches as $m) {
        echo "    - {$m}\n";
    }
    fwrite(STDERR, "\nVERIFICAÇÃO FALHOU: nem todas as turmas estão nas salas esperadas.\n");
    exit(3);
}

echo "\nConcluído. Anuais realocados para as salas do primeiro semestre de 2026.\n";
if (! $dryRun) {
    echo "Snapshot de segurança disponível para rollback (ver passo 1).\n";
}