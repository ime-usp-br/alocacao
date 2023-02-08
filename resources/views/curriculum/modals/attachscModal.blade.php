<div class="modal fade" id="attachscModal">
   <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h4 class="modal-title">Adicionar Turma a Grade Curricular</h4>
            </div>
            <form id="attachscForm" action="{{ route('courseinformations.attach') }}" method="POST"
            enctype="multipart/form-data"
            >

            @method('patch')
            @csrf

            <input name="numsemidl" value="{{$semester}}" type="hidden">
            <input name="nomcur" value="{{$course->nomcur}}" type="hidden">
            <input name="perhab" value="{{$course->perhab}}" type="hidden">
            <input name="tipobg" value="O" type="hidden">
            <div class="modal-body">
                <div class="row custom-form-group align-items-center">
                    <div class="col-12 col-lg-3 text-lg-right">
                        <label>Habilitação</label>   
                    </div> 
                    <div class="col-12 col-lg-9">

                        <select id="codhab" name="codhab" class="custom-form-control">                            
                            @foreach(array_column(\App\Models\CourseInformation::select(["codhab","nomhab"])
                                ->where("nomcur",$course->nomcur)
                                ->where("perhab", $course->perhab)
                                ->get()->sortBy("codhab")->toArray(),"codhab", "nomhab") as $nomhab=>$codhab)
                                <option value="{{ $codhab }}">{{ $nomhab }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="row custom-form-group align-items-center">
                    <div class="col-12 col-lg-3 text-lg-right">
                        <label>Código da turma</label>   
                    </div> 
                    <div class="col-12 col-lg-9">
                        <input class="custom-form-control" id="coddis" name="coddis" type="text" style="max-width:150px;">
                    </div>
                </div>

                <div id="msn-div">
                </div>

                <div id="schoolclasses-div">
                </div>
            </div>
            <div class="modal-footer">
                <button id="btn-attachscModal" class="btn btn-default" type="submit">Adicionar</button>
                <button id="btn-searchscModal" class="btn btn-default">Buscar</button>
                <button class="btn btn-default" type="button" data-dismiss="modal">Fechar</button>
            </div>
            </form>
        </div>
    </div>
</div>