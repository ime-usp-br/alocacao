@extends('layouts.app')

@section('content')
@if ($errors->any())
    <div class="alert alert-danger">
        <ul>
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
<div id="flash-message" class="flash-message">
@foreach (['danger', 'warning', 'success', 'info'] as $msg)
    @if(Session::has('alert-' . $msg))
    <p class="alert alert-{{ $msg }}">{{ Session::get('alert-' . $msg) }}</p>
    <?php Session::forget('alert-' . $msg) ?>
    @endif
@endforeach
</div>
@if(Auth::check())
    <div id="layout_menu">
        <ul id="menulateral" class="menulist">
            <li class="menuHeader">Acesso Restrito</li>
            <li>
                <a href="{{ route('home') }}">Página Inicial</a>
            </li>
            @can("editar usuario")
                <li>
                    <a href="{{ route('users.index') }}">Usuários</a>
                    <ul>
                        <li>
                            <a href="{{ route('users.loginas') }}">Logar Como</a>
                        </li>
                    </ul>
                </li>
            @endcan
            @can("visualizar periodo letivo")
                <li>
                    <a href="{{ route('schoolterms.index') }}">Períodos Letivos</a>

                    <ul>
                        <li>
                            <a href="{{ route('schoolterms.create') }}">Cadastrar</a>
                        </li>
                    </ul>
                </li>
            @endcan
            @can("visualizar grade curricular")
                <li>
                    <a href="{{ route('curriculum.index') }}">Grade Curricular</a>
                </li>
            @endcan
            @can("visualizar turmas")
                <li>
                    <a href="{{ route('schoolclasses.index') }}">Turmas Internas</a>
                </li>
            @endcan
            @can("visualizar turmas externas")
                <li>
                    <a href="{{ route('schoolclasses.externals') }}">Turmas Externas</a>
                </li>
            @endcan
            @can("visualizar dobradinhas")
                <li>
                    <a href="{{ route('fusions.index') }}">Dobradinhas</a>
                </li>
            @endcan
            @can("visualizar oferecimentos especiais")
                <li>
                    <a href="{{ route('specialoffers.index') }}">Oferecimentos Especiais</a>
                </li>
            @endcan
            @can("visualizar salas")
                <li>
                    <a href="{{ route('rooms.index') }}">Salas</a>
                </li>
            @endcan
            @can("visualizar observações")
                <li>
                    <a href="{{ route('observations.index') }}">Observações</a>
                    <ul>
                        <li>
                            <a href="{{ route('observations.create') }}">Cadastrar</a>
                        </li>
                    </ul>
                </li>
            @endcan
            <li>
                <form style="padding:0px;" action="{{ route('logout') }}" method="POST" id="logout_form2">
                    @csrf
                    <a onclick="document.getElementById('logout_form2').submit(); return false;">Sair</a>
                </form>
            </li>
        </ul>
    </div>
@endif
@endsection

@section('javascripts_bottom')
  @parent 
<script>
$( "#menulateral" ).menu();
</script>
@endsection