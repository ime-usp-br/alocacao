@extends('main')

@section('title', 'Grade Curricular')

@section('content')
  @parent 
<div id="layout_conteudo">
    <div class="row justify-content-center">
        <div class="col-12 col-sm-11 col-md-10 col-lg-9 col-xl-8">
            <h1 class='text-center'>Grade Curricular</h1>
            
            <h4 class='text-center mb-5'>{{ $schoolterm->period . ' de ' . $schoolterm->year }}</h4>

            <div class="row">
                <div class="col-12">
                    <h4 class="my-3"><b>Gradução</b></h4>
                    @foreach(App\Models\Course::whereNull("grupo")->get() as $course)     
                        <li>           
                        <a class="link"
                            href="{{ route(
                                'curriculum.semesters',
                                $course
                            ) }}"
                        >
                            {!! $course->nomcur." - ".ucfirst($course->perhab) !!}
                        </a>
                        </li>
                    @endforeach     
                    <li>                 
                        <a class="link"
                            href="{{ route('curriculum.semesters.licnot') }}"
                        >
                            Matemática - Licenciatura - Noturno
                        </a>    
                    </li>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection