@extends('main')

@section('title', 'Editar perfis')

@section('content')
    @parent
    <div id="layout_conteudo">
        <div class="justify-content-center">
            <div class="col-md-12">
                <h1 class='text-center'>
                    Editar usuário
                </h1>

                <p class="alert alert-info rounded-0">
                    <b>Atenção:</b>
                    Os campos assinalados com * são de preenchimento obrigatório.
                </p>

                <form method="POST"
                    action="{{ route('users.update', $user) }}"
                >
                    @method('patch')
                    @csrf

                    @include('users.partials.form', ['buttonText' => 'Editar'])
                </form>
            </div>
        </div>
    </div>
@endsection