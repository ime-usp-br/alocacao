@extends('main')

@section('title', 'Log do Solver #' . $solverLog->id)

@section('content')
  @parent
<div id="layout_conteudo">
    <div class="justify-content-center">
        <div class="col-md-12">
            <h1 class='text-center mb-4'>Log do Solver #{{ $solverLog->id }}</h1>

            <div class="mb-4">
                <a href="{{ route('solverlogs.index') }}" class="btn btn-outline-secondary btn-sm">&larr; Voltar</a>
            </div>

            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    Informações Gerais
                </div>
                <div class="card-body">
                    <table class="table table-borderless table-sm" style="font-size:14px;">
                        <tr>
                            <th style="width: 200px;">Job ID</th>
                            <td style="font-family: monospace;">{{ $solverLog->job_id }}</td>
                        </tr>
                        <tr>
                            <th>Semestre</th>
                            <td>{{ $solverLog->schoolTerm ? $solverLog->schoolTerm->year . '.' . $solverLog->schoolTerm->period : '-' }}</td>
                        </tr>
                        <tr>
                            <th>Status</th>
                            <td>{{ $solverLog->status }}</td>
                        </tr>
                        <tr>
                            <th>Alocadas</th>
                            <td>{{ $solverLog->allocations_count }}</td>
                        </tr>
                        <tr>
                            <th>Não Alocadas</th>
                            <td>{{ $solverLog->unassigned_count }}</td>
                        </tr>
                        <tr>
                            <th>Manuais</th>
                            <td>{{ $solverLog->manual_count }}</td>
                        </tr>
                        <tr>
                            <th>Enviado em</th>
                            <td>{{ $solverLog->dispatched_at ? $solverLog->dispatched_at->format('d/m/Y H:i:s') : '-' }}</td>
                        </tr>
                        <tr>
                            <th>Respondido em</th>
                            <td>{{ $solverLog->responded_at ? $solverLog->responded_at->format('d/m/Y H:i:s') : '-' }}</td>
                        </tr>
                    </table>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center">
                    <span>Payload Enviado ao Solver</span>
                    <button class="btn btn-light btn-sm" onclick="copyToClipboard('payload')">Copiar</button>
                </div>
                <div class="card-body p-0">
                    <pre id="payload" class="m-0 p-3" style="background:#f8f9fa; font-size:12px; max-height: 600px; overflow: auto;">{{ json_encode($solverLog->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center">
                    <span>Resposta do Solver</span>
                    <button class="btn btn-light btn-sm" onclick="copyToClipboard('response')">Copiar</button>
                </div>
                <div class="card-body p-0">
                    @if ($solverLog->response)
                        <pre id="response" class="m-0 p-3" style="background:#f8f9fa; font-size:12px; max-height: 600px; overflow: auto;">{{ json_encode($solverLog->response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                    @else
                        <div class="p-3 text-muted">Ainda sem resposta do solver.</div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@section('javascripts_bottom')
@parent
<script>
function copyToClipboard(elementId) {
    const text = document.getElementById(elementId).innerText;
    navigator.clipboard.writeText(text).then(function() {
        alert('Conteúdo copiado para a área de transferência.');
    }, function() {
        alert('Não foi possível copiar.');
    });
}
</script>
@endsection
