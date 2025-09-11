<?php

namespace App\Services;

use App\Models\SchoolClass;
use App\Services\SalasApiClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use Exception;
use DateTime;
use DateInterval;
use Carbon\Carbon;

class ReservationMapper
{
    private SalasApiClient $salasApiClient;
    private string $cachePrefix;

    public function __construct(SalasApiClient $salasApiClient)
    {
        $this->salasApiClient = $salasApiClient;
        $this->cachePrefix = 'salas_api:reservation_mapper:';
    }

    /**
     * Map SchoolClass to Salas API reservation payload
     *
     * @param SchoolClass $schoolClass
     * @param object|null $currentSchedule Current schedule being processed (for multi-schedule classes)
     * @return array
     * @throws Exception
     */
    public function mapSchoolClassToReservationPayload(SchoolClass $schoolClass, $currentSchedule = null): array
    {
        if (!$schoolClass->room) {
            throw new Exception('SchoolClass deve ter uma sala alocada para ser mapeada');
        }

        $payload = [
            'nome' => $this->generateReservationName($schoolClass, $currentSchedule),
            'data' => $this->getStartDate($schoolClass),
            'horario_inicio' => $this->mapStartTime($schoolClass),
            'horario_fim' => $this->mapEndTime($schoolClass),
            'sala_id' => $this->getSalaIdFromNome($schoolClass->room->nome),
            'finalidade_id' => 1, // Graduação (padrão conforme AC2)
            'tipo_responsaveis' => 'eu', // Padrão conforme AC2
        ];

        // Adicionar recorrência se a turma tem horários
        if ($schoolClass->classschedules->isNotEmpty()) {
            $repeatDays = $this->mapDaysToRepeatDays($schoolClass->classschedules);
            if (!empty($repeatDays)) {
                $payload['repeat_days'] = $repeatDays;
                $payload['repeat_until'] = $this->getEndDate($schoolClass);
            }
        }

        $this->logMapping($schoolClass, $payload);

        return $payload;
    }

    /**
     * Get sala_id from nome with caching
     *
     * @param string $nomeRoom
     * @return int
     * @throws Exception
     */
    public function getSalaIdFromNome(string $nomeRoom): int
    {
        $cacheKey = $this->cachePrefix . 'room_id:' . md5($nomeRoom);

        // Primeiro, verificar se já temos o resultado do pré-carregamento
        if (Cache::has($cacheKey)) {
            $salaId = Cache::get($cacheKey);
            $this->log('debug', 'Sala encontrada no cache pré-carregado', [
                'original_name' => $nomeRoom,
                'sala_id' => $salaId
            ]);
            return $salaId;
        }

        $ttl = config('salas.cache.ttl.rooms', 3600);
        return Cache::remember($cacheKey, $ttl, function () use ($nomeRoom) {
            $mappedName = $this->mapRoomName($nomeRoom);

            try {
                // Buscar na API Salas (fallback se não estiver em cache)
                $salas = $this->salasApiClient->get('/api/v1/salas');

                foreach ($salas['data'] as $sala) {
                    if ($sala['nome'] === $mappedName) {
                        $this->log('debug', 'Sala mapeada com sucesso via API', [
                            'original_name' => $nomeRoom,
                            'mapped_name' => $mappedName,
                            'sala_id' => $sala['id']
                        ]);
                        return $sala['id'];
                    }
                }

                throw new Exception("Sala não encontrada na API: {$nomeRoom} (mapeada para: {$mappedName})");
            } catch (Exception $e) {
                $this->log('error', 'Erro ao buscar sala na API', [
                    'original_name' => $nomeRoom,
                    'mapped_name' => $mappedName,
                    'error' => $e->getMessage()
                ]);
                throw $e;
            }
        });
    }

    /**
     * Map room name from allocation system to Salas system
     *
     * @param string $roomName
     * @return string
     * @throws Exception when room cannot be mapped
     */
    private function mapRoomName(string $roomName): string
    {
        $config = config('salas.room_mapping');
        
        // Verificar se a sala está na lista de salas ignoradas
        $ignoredRooms = $config['ignored_rooms'] ?? [];
        if (in_array($roomName, $ignoredRooms)) {
            throw new Exception("Sala '{$roomName}' não existe na API Salas (sala virtual ou externa)");
        }
        
        // Casos especiais definidos na configuração
        $specialCases = $config['special_cases'] ?? [];

        // Primeiro, verifica os casos especiais da configuração
        if (isset($specialCases[$roomName])) {
            return $specialCases[$roomName];
        }

        // Lógica baseada no código do Reservation.php, mas adaptada para API Salas
        // Os auditórios no sistema Salas mantêm nomes completos (conforme SalaSeeder)
        if ($roomName == "Auditório Jacy Monteiro") {
            return "Auditório Jacy Monteiro"; // Nome completo na API Salas
        } elseif ($roomName == "Auditório Antonio Gilioli") {
            return "Auditório Antonio Gilioli"; // Nome completo na API Salas
        } else {
            // Para salas padrão (A132, B16, etc.)
            // Verificar se já é um formato de 4 caracteres (ex: A132, B144)
            if (preg_match('/^[A-Z]\d{3}$/', $roomName)) {
                return $roomName; // A132, B144 -> manter como está
            }
            
            // Para formatos de 3 caracteres (ex: B16, B07), manter como está
            // pois a API Salas tem alguns com 2 dígitos (B01, B16) e outros com 3 (B101)
            if (preg_match('/^[A-Z]\d{2}$/', $roomName)) {
                return $roomName; // B16 -> B16 (não converter para B016)
            }
            
            // Se não se encaixa nos padrões conhecidos, usar nome original
            return $roomName;
        }
    }

    /**
     * Generate reservation name based on SchoolClass
     * Baseado na lógica do Requisition::createFromSchoolClass()
     * 
     * For classes with multiple schedules, adds day identifier to avoid conflicts:
     * Single schedule: "Aula - MAC0110 T.01"
     * Multiple schedules: "Aula - MAC0110 T.01 (Ter)", "Aula - MAC0110 T.01 (Qui)"
     *
     * @param SchoolClass $schoolClass
     * @param object|null $currentSchedule Current schedule being processed (for multi-schedule classes)
     * @return string
     */
    private function generateReservationName(SchoolClass $schoolClass, $currentSchedule = null): string
    {
        $titulo = "";

        if ($schoolClass->fusion) {
            if ($schoolClass->fusion->schoolclasses->pluck("coddis")->unique()->count() == 1) {
                $titulo .= $schoolClass->fusion->schoolclasses[0]->coddis . " ";
                foreach (range(0, count($schoolClass->fusion->schoolclasses) - 1) as $y) {
                    $titulo .= "T." . substr($schoolClass->fusion->schoolclasses[$y]->codtur, -2, 2);
                    $titulo .= $y != count($schoolClass->fusion->schoolclasses) - 1 ? "/" : "";
                }
            } elseif ($schoolClass->fusion->schoolclasses()->where("tiptur", "Graduação")->get()->count() == $schoolClass->fusion->schoolclasses->count()) {
                foreach (range(0, count($schoolClass->fusion->schoolclasses) - 1) as $y) {
                    $titulo .= $schoolClass->fusion->schoolclasses[$y]->coddis . " T." . substr($schoolClass->fusion->schoolclasses[$y]->codtur, -2, 2);
                    $titulo .= $y != count($schoolClass->fusion->schoolclasses) - 1 ? "/" : "";
                }
            } else {
                foreach (range(0, count($schoolClass->fusion->schoolclasses) - 1) as $y) {
                    $titulo .= $schoolClass->fusion->schoolclasses[$y]->coddis;
                    $titulo .= $y != count($schoolClass->fusion->schoolclasses) - 1 ? "/" : "";
                }
                $titulo .= " T." . substr($schoolClass->fusion->master->codtur, -2, 2);
            }
        } elseif ($schoolClass->tiptur == "Pós Graduação") {
            $titulo = $schoolClass->coddis . " T.00";
        } else {
            $titulo = $schoolClass->coddis . " T." . substr($schoolClass->codtur, -2, 2);
        }

        $baseName = "Aula - " . $titulo;
        
        // Add day identifier for multi-schedule classes to avoid conflicts
        if ($currentSchedule && $schoolClass->classschedules->count() > 1) {
            $dayMapping = [
                'dom' => 'Dom', 'seg' => 'Seg', 'ter' => 'Ter', 'qua' => 'Qua',
                'qui' => 'Qui', 'sex' => 'Sex', 'sab' => 'Sab'
            ];
            
            $dayAbbr = $dayMapping[$currentSchedule->diasmnocp] ?? strtoupper($currentSchedule->diasmnocp);
            $baseName .= " (" . $dayAbbr . ")";
        }

        return $baseName;
    }

    /**
     * Map class schedules to repeat_days array
     *
     * @param Collection $classSchedules
     * @return array
     */
    private function mapDaysToRepeatDays(Collection $classSchedules): array
    {
        $dayMapping = [
            'dom' => 0, 'seg' => 1, 'ter' => 2, 'qua' => 3,
            'qui' => 4, 'sex' => 5, 'sab' => 6
        ];

        $repeatDays = [];

        foreach ($classSchedules as $schedule) {
            if (isset($dayMapping[$schedule->diasmnocp])) {
                $repeatDays[] = $dayMapping[$schedule->diasmnocp];
            }
        }

        return array_values(array_unique($repeatDays));
    }

    /**
     * Get start time from the appropriate class schedule
     * When SchoolClass has exactly 1 schedule (e.g., from temp clone in migration),
     * use that specific schedule instead of defaulting to first()
     *
     * @param SchoolClass $schoolClass
     * @return string
     */
    private function mapStartTime(SchoolClass $schoolClass): string
    {
        $schedules = $schoolClass->classschedules;
        
        if ($schedules->isEmpty()) {
            throw new Exception('SchoolClass deve ter pelo menos um horário de aula');
        }

        // When processing single schedule (temp clone from migration), use that specific schedule
        // Otherwise, fallback to first() for backward compatibility
        $targetSchedule = $schedules->first();

        return (new DateTime($targetSchedule->horent))->format("G:i");
    }

    /**
     * Get end time from the appropriate class schedule
     * When SchoolClass has exactly 1 schedule (e.g., from temp clone in migration),
     * use that specific schedule instead of defaulting to first()
     *
     * @param SchoolClass $schoolClass
     * @return string
     */
    private function mapEndTime(SchoolClass $schoolClass): string
    {
        $schedules = $schoolClass->classschedules;
        
        if ($schedules->isEmpty()) {
            throw new Exception('SchoolClass deve ter pelo menos um horário de aula');
        }

        // When processing single schedule (temp clone from migration), use that specific schedule
        // Otherwise, fallback to first() for backward compatibility
        $targetSchedule = $schedules->first();

        // Replicar lógica do Requisition.php para horário de fim
        if (explode(":", $targetSchedule->horsai)[1] == "00") {
            return (new DateTime($targetSchedule->horsai))->sub(new DateInterval("PT1M"))->format("G:i");
        } else {
            return (new DateTime($targetSchedule->horsai))->format("G:i");
        }
    }

    /**
     * Get start date (today)
     *
     * @param SchoolClass $schoolClass
     * @return string
     */
    private function getStartDate(SchoolClass $schoolClass): string
    {
        return date("Y-m-d");
    }

    /**
     * Get end date from school term
     *
     * @param SchoolClass $schoolClass
     * @return string
     */
    private function getEndDate(SchoolClass $schoolClass): string
    {
        return Carbon::createFromFormat('d/m/Y', $schoolClass->schoolterm->dtamaxres)->format("Y-m-d");
    }

    /**
     * Clear salas cache
     *
     * @return void
     */
    public function clearSalasCache(): void
    {
        $pattern = $this->cachePrefix . 'room_id:*';
        
        // Laravel doesn't have Cache::forgetByPattern, so we'll use a more targeted approach
        $this->log('info', 'Clearing salas cache manually - consider implementing pattern-based cache clearing');
    }

    /**
     * Log mapping operation
     *
     * @param SchoolClass $schoolClass
     * @param array $payload
     * @return void
     */
    private function logMapping(SchoolClass $schoolClass, array $payload): void
    {
        // Enhanced logging to debug schedule mapping
        $scheduleDetails = $schoolClass->classschedules->map(function ($schedule) {
            return [
                'day' => $schedule->diasmnocp,
                'start' => $schedule->horent,
                'end' => $schedule->horsai
            ];
        })->toArray();

        $this->log('info', 'SchoolClass mapeada para payload da API Salas', [
            'schoolclass_id' => $schoolClass->id,
            'disciplina' => $schoolClass->coddis,
            'turma' => $schoolClass->codtur,
            'sala_nome' => $schoolClass->room->nome,
            'payload_nome' => $payload['nome'],
            'payload_horario_inicio' => $payload['horario_inicio'],
            'payload_horario_fim' => $payload['horario_fim'],
            'sala_id' => $payload['sala_id'],
            'repeat_days' => $payload['repeat_days'] ?? null,
            'schedules_count' => count($scheduleDetails),
            'schedule_details' => $scheduleDetails,
        ]);
    }

    /**
     * Log messages with context
     *
     * @param string $level
     * @param string $message
     * @param array $context
     * @return void
     */
    private function log(string $level, string $message, array $context = []): void
    {
        $context['component'] = 'ReservationMapper';
        Log::$level($message, $context);
    }

    /**
     * Get all available salas from API with caching
     *
     * @return array
     * @throws Exception
     */
    public function getAllSalas(): array
    {
        $cacheKey = $this->cachePrefix . 'all_salas';

        $ttl = config('salas.cache.ttl.rooms', 3600);
        return Cache::remember($cacheKey, $ttl, function () {
            try {
                $response = $this->salasApiClient->get('/api/v1/salas');
                return $response['data'];
            } catch (Exception $e) {
                $this->log('error', 'Erro ao buscar lista de salas da API', [
                    'error' => $e->getMessage()
                ]);
                throw $e;
            }
        });
    }

    /**
     * Check if a room is in the ignored list
     *
     * @param string $roomName
     * @return bool
     */
    public function isIgnoredRoom(string $roomName): bool
    {
        $config = config('salas.room_mapping');
        $ignoredRooms = $config['ignored_rooms'] ?? [];
        return in_array($roomName, $ignoredRooms);
    }

    /**
     * Validate if a room name can be mapped
     *
     * @param string $roomName
     * @return bool
     */
    public function canMapRoom(string $roomName): bool
    {
        try {
            $this->getSalaIdFromNome($roomName);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Map Urano data to Salas API reservation payload
     *
     * @param array $uranoData Transformed Urano reservation data
     * @return array
     * @throws Exception
     */
    public function mapUranoDataToReservationPayload(array $uranoData): array
    {
        // Validate required fields
        $requiredFields = ['data', 'hora_inicio', 'hora_fim', 'sala_nome', 'titulo', 'solicitante'];
        foreach ($requiredFields as $field) {
            if (!isset($uranoData[$field]) || empty($uranoData[$field])) {
                throw new Exception("Campo obrigatório ausente nos dados do Urano: {$field}");
            }
        }

        $payload = [
            'nome' => $this->generateUranoReservationName($uranoData),
            'data' => Carbon::parse($uranoData['data'])->format('Y-m-d'),
            'horario_inicio' => $this->formatUranoTime($uranoData['hora_inicio']),
            'horario_fim' => $this->formatUranoTime($uranoData['hora_fim']),
            'sala_id' => $this->getSalaIdFromNome($uranoData['sala_nome']),
            'finalidade_id' => 1, // Graduação (padrão conforme AC2)
            'tipo_responsaveis' => 'eu', // Padrão conforme AC2
            'observacoes' => $this->generateUranoObservations($uranoData),
        ];

        // Add recurrence if it's a regular activity
        if (isset($uranoData['atividade_regular']) && $uranoData['atividade_regular']) {
            // For Urano imports, we typically create single reservations
            // rather than recurring ones to maintain data integrity
            $payload['recorrencia'] = null;
        }

        $this->log('debug', 'Payload da API Salas criado a partir de dados do Urano', [
            'urano_data' => $uranoData,
            'payload' => $payload
        ]);

        return $payload;
    }

    /**
     * Generate reservation name from Urano data
     *
     * @param array $uranoData
     * @return string
     */
    private function generateUranoReservationName(array $uranoData): string
    {
        $name = $uranoData['titulo'];
        
        if (isset($uranoData['solicitante']) && !empty($uranoData['solicitante'])) {
            $name .= " - " . $uranoData['solicitante'];
        }

        // Limit name length for API compatibility
        if (strlen($name) > 100) {
            $name = substr($name, 0, 97) . '...';
        }

        return $name;
    }

    /**
     * Format Urano time to API format
     *
     * @param string $uranoTime Time from Urano (can be various formats)
     * @return string Formatted time (HH:MM)
     */
    private function formatUranoTime(string $uranoTime): string
    {
        try {
            // Handle various Urano time formats
            if (strpos($uranoTime, ':') !== false) {
                // Already in HH:MM or HH:MM:SS format
                $parts = explode(':', $uranoTime);
                // Use G:i format (no leading zero for hours) as required by Salas API
                return sprintf('%d:%02d', (int)$parts[0], (int)$parts[1]);
            } else {
                // Handle other possible formats
                $carbon = Carbon::parse($uranoTime);
                return $carbon->format('G:i'); // G:i format for Salas API
            }
        } catch (Exception $e) {
            throw new Exception("Formato de hora inválido no Urano: {$uranoTime}");
        }
    }

    /**
     * Generate observations from Urano data
     *
     * @param array $uranoData
     * @return string
     */
    private function generateUranoObservations(array $uranoData): string
    {
        $observations = [];

        $observations[] = "Importado do sistema Urano";
        
        if (isset($uranoData['reserva_id'])) {
            $observations[] = "ID Reserva Urano: {$uranoData['reserva_id']}";
        }
        
        if (isset($uranoData['requisicao_id'])) {
            $observations[] = "ID Requisição Urano: {$uranoData['requisicao_id']}";
        }

        if (isset($uranoData['participantes']) && $uranoData['participantes'] > 0) {
            $observations[] = "Participantes: {$uranoData['participantes']}";
        }

        if (isset($uranoData['email']) && !empty($uranoData['email'])) {
            $observations[] = "Contato: {$uranoData['email']}";
        }

        return implode("\n", $observations);
    }
}