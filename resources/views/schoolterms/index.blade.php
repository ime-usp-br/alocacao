@extends('main')

@section('title', 'Períodos Letivos')

@section('content')
  @parent 
<div id="layout_conteudo">
    <div class="justify-content-center">
        <div class="col-md-12">
            <h1 class='text-center mb-5'>Períodos Letivos</h1>

            @if (count($schoolterms) > 0)
                <div class="d-flex justify-content-center">
                    <div class="col-md-6">
                    <table class="table table-bordered table-striped table-hover" style="font-size:15px;">
                        <tr>
                            <th>Ano</th>
                            <th>Período</th>
                            <th>Data Maxima de Reserva</th>
                            <th></th>
                        </tr>

                        @foreach($schoolterms as $schoolterm)
                            <tr>
                                <td>{{ $schoolterm->year }}</td>
                                <td style="white-space: nowrap;">{{ $schoolterm->period }}</td>
                                <td>{{ $schoolterm->dtamaxres }}</td>
                            <td class="text-center" style="white-space: nowrap;">
                                <a class="text-dark text-decoration-none"
                                    data-toggle="tooltip" data-placement="top"
                                    title="Editar"
                                    href="{{ route('schoolterms.edit', $schoolterm) }}"
                                >
                                    <i class="fas fa-edit"></i>
                                </a>
                            </td>
                            </tr>
                        @endforeach
                    </table>
                </div>
                </div>
            @else
                <p class="text-center">Não há períodos letivos cadastrados</p>
            @endif
        </div>
    </div>
</div>
@endsection