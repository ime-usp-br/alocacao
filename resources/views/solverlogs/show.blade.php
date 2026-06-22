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

            <div class="mb-4">
                <div class="row">
                    <div class="col-6 col-md-3 mb-3">
                        <div class="card text-center h-100 border-primary">
                            <div class="card-body py-3">
                                <div class="text-muted small text-uppercase fw-semibold">Status</div>
                                <div class="fs-5 fw-bold mt-1">{{ $solverLog->status }}</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3 mb-3">
                        <div class="card text-center h-100 border-success">
                            <div class="card-body py-3">
                                <div class="text-muted small text-uppercase fw-semibold">Alocadas</div>
                                <div class="fs-3 fw-bold mt-1 text-success">{{ $solverLog->allocations_count }}</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3 mb-3">
                        <div class="card text-center h-100 border-danger">
                            <div class="card-body py-3">
                                <div class="text-muted small text-uppercase fw-semibold">Não Alocadas</div>
                                <div class="fs-3 fw-bold mt-1 text-danger">{{ $solverLog->unassigned_count }}</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3 mb-3">
                        <div class="card text-center h-100 border-warning">
                            <div class="card-body py-3">
                                <div class="text-muted small text-uppercase fw-semibold">Manuais</div>
                                <div class="fs-3 fw-bold mt-1 text-warning">{{ $solverLog->manual_count }}</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3 mb-3">
                        <div class="card h-100">
                            <div class="card-body py-2 d-flex justify-content-between align-items-center" style="font-size:14px;">
                                <span class="text-muted text-uppercase fw-semibold small">Job ID</span>
                                <span style="font-family: monospace;">{{ $solverLog->job_id }}</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3 mb-3">
                        <div class="card h-100">
                            <div class="card-body py-2 d-flex justify-content-between align-items-center" style="font-size:14px;">
                                <span class="text-muted text-uppercase fw-semibold small">Semestre</span>
                                <span>{{ $solverLog->schoolTerm ? $solverLog->schoolTerm->year . '.' . $solverLog->schoolTerm->period : '-' }}</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3 mb-3">
                        <div class="card h-100">
                            <div class="card-body py-2 d-flex justify-content-between align-items-center" style="font-size:14px;">
                                <span class="text-muted text-uppercase fw-semibold small">Enviado em</span>
                                <span>{{ $solverLog->dispatched_at ? $solverLog->dispatched_at->format('d/m/Y H:i:s') : '-' }}</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3 mb-3">
                        <div class="card h-100">
                            <div class="card-body py-2 d-flex justify-content-between align-items-center" style="font-size:14px;">
                                <span class="text-muted text-uppercase fw-semibold small">Respondido em</span>
                                <span>{{ $solverLog->responded_at ? $solverLog->responded_at->format('d/m/Y H:i:s') : '-' }}</span>
                            </div>
                        </div>
                    </div>
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
    const el = document.getElementById(elementId);
    if (!el) {
        alert('Nada para copiar.');
        return;
    }
    const text = el.innerText;
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(text).then(function() {
            alert('Conteúdo copiado para a área de transferência.');
        }, function() {
            fallbackCopyText(text);
        });
    } else {
        fallbackCopyText(text);
    }
}

function fallbackCopyText(text) {
    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.select();
    try {
        document.execCommand('copy');
        alert('Conteúdo copiado para a área de transferência.');
    } catch (err) {
        alert('Não foi possível copiar.');
    }
    document.body.removeChild(textarea);
}
</script>
@endsection
