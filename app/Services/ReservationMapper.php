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
     * @return array
     * @throws Exception
     */
    public function mapSchoolClassToReservationPayload(SchoolClass $schoolClass): array
    {
        if (!$schoolClass->room) {
            throw new Exception('SchoolClass deve ter uma sala alocada para ser mapeada');
        }

        $payload = [
            'nome' => $this->generateReservationName($schoolClass),
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

        $ttl = config('salas.cache.ttl.rooms', 3600);
        return Cache::remember($cacheKey, $ttl, function () use ($nomeRoom) {
            $mappedName = $this->mapRoomName($nomeRoom);

            try {
                // Buscar na API Salas
                $salas = $this->salasApiClient->get('/api/v1/salas');

                foreach ($salas['data'] as $sala) {
                    if ($sala['nome'] === $mappedName) {
                        $this->log('debug', 'Sala mapeada com sucesso', [
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
     */
    private function mapRoomName(string $roomName): string
    {
        $config = config('salas.room_mapping');
        
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
            // Para outras salas: se tem 4 chars, mantém; senão, adiciona zero
            if (strlen($roomName) == 4) {
                return $roomName;
            } else {
                return substr($roomName, 0, 1) . "0" . substr($roomName, 1, 2);
            }
        }
    }

    /**
     * Generate reservation name based on SchoolClass
     * Baseado na lógica do Requisition::createFromSchoolClass()
     *
     * @param SchoolClass $schoolClass
     * @return string
     */
    private function generateReservationName(SchoolClass $schoolClass): string
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

        return "Aula - " . $titulo;
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
     * Get start time from first class schedule
     *
     * @param SchoolClass $schoolClass
     * @return string
     */
    private function mapStartTime(SchoolClass $schoolClass): string
    {
        $firstSchedule = $schoolClass->classschedules->first();
        if (!$firstSchedule) {
            throw new Exception('SchoolClass deve ter pelo menos um horário de aula');
        }

        return (new DateTime($firstSchedule->horent))->format("H:i");
    }

    /**
     * Get end time from first class schedule
     *
     * @param SchoolClass $schoolClass
     * @return string
     */
    private function mapEndTime(SchoolClass $schoolClass): string
    {
        $firstSchedule = $schoolClass->classschedules->first();
        if (!$firstSchedule) {
            throw new Exception('SchoolClass deve ter pelo menos um horário de aula');
        }

        // Replicar lógica do Requisition.php para horário de fim
        if (explode(":", $firstSchedule->horsai)[1] == "00") {
            return (new DateTime($firstSchedule->horsai))->sub(new DateInterval("PT1M"))->format("H:i");
        } else {
            return (new DateTime($firstSchedule->horsai))->format("H:i");
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
        $this->log('info', 'SchoolClass mapeada para payload da API Salas', [
            'schoolclass_id' => $schoolClass->id,
            'disciplina' => $schoolClass->coddis,
            'turma' => $schoolClass->codtur,
            'sala_nome' => $schoolClass->room->nome,
            'payload_nome' => $payload['nome'],
            'sala_id' => $payload['sala_id'],
            'repeat_days' => $payload['repeat_days'] ?? null,
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
}