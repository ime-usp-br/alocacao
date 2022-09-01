@extends('main')

@section('title', 'Cadastrar turma')

@section('content')
  @parent 
<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <h1 class='text-center'>Cadastrar turma</h1>

            <p class="alert alert-info rounded-0">
                <b>Atenção:</b>
                Os campos assinalados com * são de preenchimento obrigatório.
            </p>

            <form method="POST"
                action="{{ route('schoolclasses.store', $turma) }}"
            >
                @csrf
                <input name="periodoId" value="{{$turma->schoolterm->id}}" type="hidden">

                @include('schoolclasses.partials.form', ['buttonText' => 'Cadastrar'])
            </form>

            @include('schoolclasses.modals.addClassSchedule')
            @include('schoolclasses.modals.addInstructor')
        </div>
    </div>
</div>
@endsection