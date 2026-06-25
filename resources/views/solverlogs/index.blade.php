@extends('main')

@section('title', 'Logs do Solver')

@section('content')
  @parent
<div id="layout_conteudo">
    <div class="justify-content-center">
        <div class="col-md-12">
            <h1 class='text-center mb-5'>Logs do Solver</h1>

            @if ($logs->count() > 0)
                <table class="table table-bordered table-striped table-hover" style="font-size:14px;">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Job ID</th>
                            <th>Semestre</th>
                            <th>Status</th>
                            <th>Alocadas</th>
                            <th>Não Alocadas</th>
                            <th>Manuais</th>
                            <th>Enviado em</th>
                            <th>Respondido em</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($logs as $log)
                            <tr>
                                <td>{{ $log->id }}</td>
                                <td style="font-family: monospace; font-size: 12px;">{{ $log->job_id }}</td>
                                <td>{{ $log->schoolTerm ? $log->schoolTerm->year . '.' . $log->schoolTerm->period : '-' }}</td>
                                <td>
                                    @if ($log->status === 'optimal' || $log->status === 'feasible')
                                        <span class="badge badge-success">{{ $log->status }}</span>
                                    @elseif ($log->status === 'error' || $log->status === 'infeasible')
                                        <span class="badge badge-danger">{{ $log->status }}</span>
                                    @elseif ($log->status === 'solving')
                                        <span class="badge badge-warning">{{ $log->status }}</span>
                                    @elseif ($log->status === 'cancelled')
                                        <span class="badge badge-info">{{ $log->status }}</span>
                                    @else
                                        <span class="badge badge-secondary">{{ $log->status }}</span>
                                    @endif
                                </td>
                                <td>{{ $log->allocations_count }}</td>
                                <td>{{ $log->unassigned_count }}</td>
                                <td>{{ $log->manual_count }}</td>
                                <td>{{ $log->dispatched_at ? $log->dispatched_at->format('d/m/Y H:i:s') : '-' }}</td>
                                <td>{{ $log->responded_at ? $log->responded_at->format('d/m/Y H:i:s') : '-' }}</td>
                                <td class="text-center">
                                    <a href="{{ route('solverlogs.show', $log) }}" class="btn btn-outline-dark btn-sm">Ver</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <div class="d-flex justify-content-center">
                    {{ $logs->links('pagination::bootstrap-4') }}
                </div>
            @else
                <p class="text-center">Nenhum log do solver encontrado.</p>
            @endif
        </div>
    </div>
</div>
@endsection
