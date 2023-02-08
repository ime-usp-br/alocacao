@extends('main')

@section('title', 'Grade Curricular')

@section('content')
  @parent 
<div id="layout_conteudo">
    <div class="row justify-content-center">
        <div class="col-12 col-sm-11 col-md-10 col-lg-9 col-xl-8">
            <h1 class='text-center'>Grade Curricular</h1>
            
            <h4 class='text-center mb-5'>{{ $schoolterm->period . ' de ' . $schoolterm->year }}</h4>
            <h2 class='text-center mb-5'>{!! $course->nomcur." - ".ucfirst($course->perhab) !!}</h2>

            <div class="row">
                <div class="col-12">
                    @foreach($semesters as $semester)     
                        <li>           
                        <a class="link"
                            href="{{ route(
                                'curriculum.edit',
                                [$course,
                                $semester]
                            ) }}"
                        >
                            {!! $semester !!}° Semestre
                        </a>
                        </li>
                    @endforeach     
                </div>
            </div>
        </div>
    </div>
</div>
@endsection