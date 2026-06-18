<?php

/**
 * Gera payloads JSON para calibração de parâmetros do solver via Optuna.
 *
 * Regras aplicadas:
 * - Um payload por semestre letivo já ocorrido (todos exceto o mais recente).
 * - Apenas turmas internas (o builder já exclui externa=true e MAE0116).
 * - Todas as turmas são enviadas sem sala pré-alocada (preassigned_room_id = null),
 *   mesmo que historicamente já estivessem associadas a uma sala.
 * - Salas enviadas: apenas as marcadas por padrão na view de distribuição automática.
 * - Config: default do sistema (nenhum override).
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Room;
use App\Models\SchoolTerm;
use App\Services\RoomAllocationPayloadBuilder;
use Illuminate\Support\Facades\Storage;

// Salas que NÃO ficam marcadas por padrão na view de distribuição automática.
$excludedRoomNames = [
    'B05',
    'B04',
    'B07',
    'A249',
    'CEC02',
    'CEC04',
    'CEC05',
    'CEC06',
    'Auditório Jacy Monteiro',
    'Auditório Antonio Gilioli',
    'Auditório Imre Simon',
    'Online',
    'Auditório do CCSL',
    'Auditório do InovaUSP',
    'A251(CEA)',
];

$defaultRoomIds = Room::orderBy('id')
    ->get()
    ->reject(fn (Room $room) => in_array($room->nome, $excludedRoomNames, true))
    ->pluck('id')
    ->map(fn ($id) => (int) $id)
    ->values()
    ->toArray();

// Semestres já ocorridos: todos os cadastrados.
$schoolTerms = SchoolTerm::orderBy('year')
    ->orderBy('period')
    ->get();

if ($schoolTerms->isEmpty()) {
    fwrite(STDERR, "Nenhum semestre letivo encontrado.\n");
    exit(1);
}

$pastTerms = $schoolTerms->values();

$builder = new RoomAllocationPayloadBuilder();
$outputDir = 'optuna_payloads';
Storage::makeDirectory($outputDir);

$generated = [];

foreach ($pastTerms as $term) {
    $payload = $builder->build($term, $defaultRoomIds, []);

    // Força preassigned_room_id = null em todos os grupos para calibração.
    foreach ($payload['groups'] as &$group) {
        $group['preassigned_room_id'] = null;
    }
    unset($group);

    $filename = sprintf(
        '%s/optuna_payload_%d_%s.json',
        $outputDir,
        $term->id,
        $term->year . '_' . $term->period
    );

    Storage::put($filename, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    $generated[] = [
        'term_id' => $term->id,
        'term' => $term->year . ' - ' . $term->period,
        'file' => $filename,
        'groups_count' => count($payload['groups']),
        'rooms_count' => count($payload['rooms']),
    ];
}

echo "Payloads gerados em storage/app/{$outputDir}:\n";
foreach ($generated as $item) {
    echo sprintf(
        "- %s (term_id=%d): %d grupos, %d salas => %s\n",
        $item['term'],
        $item['term_id'],
        $item['groups_count'],
        $item['rooms_count'],
        $item['file']
    );
}
