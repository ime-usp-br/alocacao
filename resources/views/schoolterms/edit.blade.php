@extends('main')

@section('title', 'Editar Período Letivo')

@section('content')
  @parent 
<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <h1 class='text-center'>Editar Período Letivo</h1>

            <p class="alert alert-info rounded-0">
                <b>Atenção:</b>
                Os campos assinalados com * são de preenchimento obrigatório.
            </p>

            <form method="POST" action="{{ route('schoolterms.update', $periodo) }}" enctype='multipart/form-data'>
                @csrf
                @method('patch')
                @include('schoolterms.partials.form', ['buttonText' => 'Salvar'])
            </form>
        </div>
    </div>
</div>
@endsection