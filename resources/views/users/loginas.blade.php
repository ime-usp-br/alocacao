@extends('main')

@section('title', 'Logar Como')

@section('content')
@parent
<div id="layout_conteudo">
    <div class="justify-content-center">
        <div class="col-md-12">
            <h1 class='text-center mb-5'>Logar Como</h1>
            <form method="POST" action="{{ route('SenhaunicaLoginAs') }}">
                @csrf

            <div class="row custom-form-group justify-content-md-center">
                <div class="col col-md-2 text-lg-right">
                    <label for="email">Número USP</label>
                </div>
                <div class="col-md-auto">
                    <input class="custom-form-control" type="text" name="codpes"
                        id="codpes"
                    />
                </div>
                <div class="col col-md-2">
                    <button class="btn btn-outline-primary" type="submit">Logar</button>
                </div>
            </div>
            </form>
        </div>
    </div>
</div>
@endsection