@extends('main')

@section('title', 'Cadastrar Observação')

@section('content')
  @parent 
<div class="container">
    <div class="row">
        <div class="col-md-12">
            <h1 class='text-center'>Cadastrar Observação</h1>

            <p class="alert alert-info rounded-0">
                <b>Atenção:</b>
                Os campos assinalados com * são de preenchimento obrigatório.
            </p>

            <form method="POST"
                action="{{ route('observations.store') }}"
            >
                @csrf
                @include('observations.partials.form', ['buttonText' => 'Cadastrar'])
            </form>

        </div>
    </div>
</div>
@endsection