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
            'sala_id' => $this->getSalaIdFromNome($schoolClass->room->nome),
            'finalidade_id' => $this->mapSchoolClassToFinalidade($schoolClass),
            'tipo_responsaveis' => 'eu', // Padrão conforme AC2
        ];

        // Handle time fields based on schedule complexity
        if ($schoolClass->classschedules->isNotEmpty()) {
            $repeatDays = $this->mapDaysToRepeatDays($schoolClass->classschedules);
            if (!empty($repeatDays)) {
                $payload['repeat_days'] = $repeatDays;
                $payload['repeat_until'] = $this->getEndDate($schoolClass);

                // API Salas requires day_times for ALL recurring reservations
                // Use day_times structure for all recurring schedules (API requirement)
                $payload['day_times'] = $this->buildDayTimesArray($schoolClass->classschedules);

                if ($this->hasDistinctTimes($schoolClass->classschedules)) {
                    $this->log('info', 'Using day_times structure for distinct schedules', [
                        'schoolclass_id' => $schoolClass->id,
                        'distinct_schedules' => count($schoolClass->classschedules),
                        'day_times' => $payload['day_times']
                    ]);
                } else {
                    $this->log('info', 'Using day_times structure for uniform schedules (API requirement)', [
                        'schoolclass_id' => $schoolClass->id,
                        'uniform_schedules' => count($schoolClass->classschedules),
                        'day_times' => $payload['day_times']
                    ]);
                }
            } else {
                // Single schedule or no valid schedules
                $payload['horario_inicio'] = $this->mapStartTime($schoolClass);
                $payload['horario_fim'] = $this->mapEndTime($schoolClass);
            }
        } else {
            throw new Exception('SchoolClass deve ter pelo menos um horário de aula definido');
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

        $logData = [
            'schoolclass_id' => $schoolClass->id,
            'disciplina' => $schoolClass->coddis,
            'turma' => $schoolClass->codtur,
            'sala_nome' => $schoolClass->room->nome,
            'payload_nome' => $payload['nome'],
            'sala_id' => $payload['sala_id'],
            'repeat_days' => $payload['repeat_days'] ?? null,
            'schedules_count' => count($scheduleDetails),
            'schedule_details' => $scheduleDetails,
        ];

        // Add time field information based on payload structure
        if (isset($payload['day_times'])) {
            $logData['time_structure'] = 'day_times';
            $logData['day_times'] = $payload['day_times'];
            $logData['distinct_times'] = true;
        } else {
            $logData['time_structure'] = 'traditional';
            $logData['payload_horario_inicio'] = $payload['horario_inicio'] ?? null;
            $logData['payload_horario_fim'] = $payload['horario_fim'] ?? null;
            $logData['distinct_times'] = false;
        }

        $this->log('info', 'SchoolClass mapeada para payload da API Salas', $logData);
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
            'finalidade_id' => $this->mapUranoDataToFinalidade($uranoData),
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

    /**
     * Check if SchoolClass has distinct times for different days
     *
     * @param Collection $classSchedules
     * @return bool
     */
    private function hasDistinctTimes(Collection $classSchedules): bool
    {
        if ($classSchedules->count() <= 1) {
            return false;
        }

        $uniqueTimes = $classSchedules->map(function ($schedule) {
            return $schedule->horent . '-' . $schedule->horsai;
        })->unique();

        // If we have more than one unique time combination, we have distinct times
        return $uniqueTimes->count() > 1;
    }

    /**
     * Build day_times array for API payload
     *
     * @param Collection $classSchedules
     * @return array
     */
    private function buildDayTimesArray(Collection $classSchedules): array
    {
        $dayMapping = [
            'dom' => 0, 'seg' => 1, 'ter' => 2, 'qua' => 3,
            'qui' => 4, 'sex' => 5, 'sab' => 6
        ];

        $dayTimes = [];

        foreach ($classSchedules as $schedule) {
            $dayNumber = $dayMapping[$schedule->diasmnocp] ?? null;

            if ($dayNumber !== null) {
                $startTime = (new DateTime($schedule->horent))->format("G:i");
                $endTime = $this->formatEndTime($schedule->horsai);

                $dayTimes[(string)$dayNumber] = [
                    'start' => $startTime,
                    'end' => $endTime
                ];
            }
        }

        return $dayTimes;
    }

    /**
     * Format end time with the same logic as mapEndTime
     *
     * @param string $horsai
     * @return string
     */
    private function formatEndTime(string $horsai): string
    {
        // Replicar lógica do Requisition.php para horário de fim
        if (explode(":", $horsai)[1] == "00") {
            return (new DateTime($horsai))->sub(new DateInterval("PT1M"))->format("G:i");
        } else {
            return (new DateTime($horsai))->format("G:i");
        }
    }

    /**
     * Map SchoolClass type to appropriate finalidade ID
     *
     * Available finalidades from Salas API:
     * 1 = Graduação, 2 = Pós-Graduação, 3 = Especialização, 4 = Extensão,
     * 5 = Defesa, 6 = Qualificação, 7 = Reunião, 8 = Evento
     *
     * @param SchoolClass $schoolClass
     * @return int
     */
    private function mapSchoolClassToFinalidade(SchoolClass $schoolClass): int
    {
        // Default fallback to Graduação
        $finalidadeId = 1;

        if (isset($schoolClass->tiptur) && !empty($schoolClass->tiptur)) {
            switch ($schoolClass->tiptur) {
                case 'Graduação':
                    $finalidadeId = 1; // Graduação
                    break;
                case 'Pós Graduação':
                    $finalidadeId = 2; // Pós-Graduação
                    break;
                case 'Especialização':
                    $finalidadeId = 3; // Especialização
                    break;
                case 'Extensão':
                    $finalidadeId = 4; // Extensão
                    break;
                default:
                    // Unknown type, fallback to Graduação
                    $finalidadeId = 1;
                    $this->log('warning', 'Tipo de turma desconhecido, usando Graduação como fallback', [
                        'schoolclass_id' => $schoolClass->id,
                        'tiptur' => $schoolClass->tiptur,
                        'finalidade_mapped' => $finalidadeId
                    ]);
                    break;
            }
        }

        $this->log('debug', 'Finalidade mapeada para SchoolClass', [
            'schoolclass_id' => $schoolClass->id,
            'tiptur' => $schoolClass->tiptur,
            'finalidade_id' => $finalidadeId
        ]);

        return $finalidadeId;
    }

    /**
     * Map Urano data to appropriate finalidade ID
     *
     * Available finalidades from Salas API:
     * 1 = Graduação, 2 = Pós-Graduação, 3 = Especialização, 4 = Extensão,
     * 5 = Defesa, 6 = Qualificação, 7 = Reunião, 8 = Evento
     *
     * @param array $uranoData
     * @return int
     */
    private function mapUranoDataToFinalidade(array $uranoData): int
    {
        // Default fallback to Graduação
        $finalidadeId = 1;

        // Check if we have activity type information from Urano
        if (isset($uranoData['tipo_atividade']) && !empty($uranoData['tipo_atividade'])) {
            $tipoAtividade = strtolower(trim($uranoData['tipo_atividade']));

            // Map common Urano activity types to finalidades
            if (str_contains($tipoAtividade, 'graduação') || str_contains($tipoAtividade, 'graduacao')) {
                $finalidadeId = 1; // Graduação
            } elseif (str_contains($tipoAtividade, 'pós') || str_contains($tipoAtividade, 'pos') ||
                      str_contains($tipoAtividade, 'mestrado') || str_contains($tipoAtividade, 'doutorado')) {
                $finalidadeId = 2; // Pós-Graduação
            } elseif (str_contains($tipoAtividade, 'especialização') || str_contains($tipoAtividade, 'especializacao')) {
                $finalidadeId = 3; // Especialização
            } elseif (str_contains($tipoAtividade, 'extensão') || str_contains($tipoAtividade, 'extensao')) {
                $finalidadeId = 4; // Extensão
            } elseif (str_contains($tipoAtividade, 'defesa')) {
                $finalidadeId = 5; // Defesa
            } elseif (str_contains($tipoAtividade, 'qualificação') || str_contains($tipoAtividade, 'qualificacao')) {
                $finalidadeId = 6; // Qualificação
            } elseif (str_contains($tipoAtividade, 'reunião') || str_contains($tipoAtividade, 'reuniao')) {
                $finalidadeId = 7; // Reunião
            } elseif (str_contains($tipoAtividade, 'evento')) {
                $finalidadeId = 8; // Evento
            }
        }

        // Check title for keywords if activity type is not available or didn't match
        if ($finalidadeId === 1 && isset($uranoData['titulo']) && !empty($uranoData['titulo'])) {
            $titulo = strtolower(trim($uranoData['titulo']));

            if (str_contains($titulo, 'defesa')) {
                $finalidadeId = 5; // Defesa
            } elseif (str_contains($titulo, 'qualificação') || str_contains($titulo, 'qualificacao')) {
                $finalidadeId = 6; // Qualificação
            } elseif (str_contains($titulo, 'reunião') || str_contains($titulo, 'reuniao')) {
                $finalidadeId = 7; // Reunião
            } elseif (str_contains($titulo, 'evento') || str_contains($titulo, 'seminário') ||
                      str_contains($titulo, 'seminario') || str_contains($titulo, 'workshop')) {
                $finalidadeId = 8; // Evento
            } elseif (str_contains($titulo, 'pós') || str_contains($titulo, 'pos') ||
                      str_contains($titulo, 'mestrado') || str_contains($titulo, 'doutorado')) {
                $finalidadeId = 2; // Pós-Graduação
            } elseif (str_contains($titulo, 'extensão') || str_contains($titulo, 'extensao')) {
                $finalidadeId = 4; // Extensão
            }
        }

        $this->log('debug', 'Finalidade mapeada para dados do Urano', [
            'urano_titulo' => $uranoData['titulo'] ?? null,
            'urano_tipo_atividade' => $uranoData['tipo_atividade'] ?? null,
            'finalidade_id' => $finalidadeId
        ]);

        return $finalidadeId;
    }
}