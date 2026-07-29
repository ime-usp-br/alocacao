<?php

namespace App\Services;

use App\Models\SchoolTerm;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente do microsserviço preditivo Skuld (https://skuld.dos.ime.usp.br).
 *
 * Consulta o endpoint síncrono /api/v1/predict e devolve um mapa
 * [coddis|codtur => estmtr] que o RoomAllocationPayloadBuilder usa para
 * substituir o estmtr APENAS no payload enviado ao solver, sem escrever
 * no banco local.
 */
class SkuldPredictionService
{
    /**
     * Busca as predições do Skuld para o semestre alvo.
     *
     * @param SchoolTerm $schoolTerm Semestre letivo alvo.
     * @return array<string, int> Mapa "coddis|codtur" => estmtr previsto.
     */
    public function predict(SchoolTerm $schoolTerm): array
    {
        $baseUrl = rtrim((string) config('alocacao.skuld.url'), '/');
        $apiVersion = (string) config('alocacao.skuld.api_version', 'v1');
        $timeout = (int) config('alocacao.skuld.timeout', 30);
        $verifySsl = (bool) config('alocacao.skuld.verify_ssl', true);

        // Skuldusa o formato YYYYX (ex.: 20262 para 2026/2º semestre).
        $anoSem = (int) sprintf('%d%d', $schoolTerm->year, $schoolTerm->period);

        try {
            $http = Http::withHeaders(['Accept' => 'application/json'])
                ->timeout($timeout);

            if (! $verifySsl) {
                $http = $http->withoutVerifying();
            }

            $response = $http->get("{$baseUrl}/api/{$apiVersion}/predict", [
                'ano_sem' => $anoSem,
            ]);
        } catch (\Throwable $e) {
            Log::warning('SkuldPredictionService: request failed', [
                'ano_sem' => $anoSem,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        if (! $response->successful()) {
            Log::warning('SkuldPredictionService: predict returned error', [
                'ano_sem' => $anoSem,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [];
        }

        $predicoes = $response->json('predicoes', []);

        if (! is_array($predicoes)) {
            return [];
        }

        $map = [];
        foreach ($predicoes as $pred) {
            $coddis = $pred['coddis'] ?? null;
            $codtur = $pred['codtur'] ?? null;
            $estmtr = $pred['estmtr'] ?? null;

            if ($coddis === null || $codtur === null || $estmtr === null) {
                continue;
            }

            $map["{$coddis}|{$codtur}"] = (int) $estmtr;
        }

        Log::info('SkuldPredictionService: predictions loaded', [
            'ano_sem' => $anoSem,
            'total_turmas' => $response->json('total_turmas', count($map)),
            'mapped' => count($map),
        ]);

        return $map;
    }
}