@extends('main')

@section('title', 'Usuários')

@section('content')
@parent
<div id="layout_conteudo">
    <div class="justify-content-center">
        <div class="col-md-12">
            <h1 class='text-center mb-5'>Usuários</h1>

            @if (count($users) > 0)
                <table class="table table-bordered table-striped table-hover">
                    <tr>
                        <th>Nome</th>
                        <th>Número USP</th>
                        <th>E-mail</th>
                        <th>Perfil</th>
                        <th></th>
                    </tr>

                    @foreach($users as $usuario)
                        <tr>
                            <td>{{ $usuario->name }}</td>
                            <td>{{ $usuario->codpes }}</td>
                            <td>{{ $usuario->email }}</td>
                            <td>{{ $usuario->getRoleNames()->implode(', ') }}</td>
                            <td class="text-center">
                                <a class="text-dark text-decoration-none"
                                    data-toggle="tooltip" data-placement="top"
                                    title="Editar"
                                    href="{{ route('users.edit', $usuario) }}"
                                >
                                    <i class="fas fa-edit"></i>
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </table>
            @else
                <p class="text-center">Não há usuários cadastrados</p>
            @endif
        </div>
    </div>
</div>
@endsection