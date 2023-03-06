<div class="modal fade" id="addSpecialOfferModal">
   <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h4 class="modal-title">Adicionar Oferecimento Especial</h4>
            </div>
            <form id="addSpecialOfferForm" action="{{ route('specialoffers.store') }}" method="POST"
            enctype="multipart/form-data"
            >
            @csrf
            <div class="modal-body">
                <div class="row custom-form-group align-items-center">
                    <div class="col-12 col-lg-3 text-lg-right">
                        <label>Curso</label>   
                    </div> 
                    <div class="col-12 col-lg-7">

                        <select id="nomcur" name="nomcur" class="custom-form-control">                            
                            @foreach(App\Models\Course::all()->pluck("nomcur")->unique()->toArray() as $nomcur)
                                <option value="{{ $nomcur }}">{{ $nomcur }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12 col-lg-2">
                    </div>
                </div>
                <div class="row custom-form-group align-items-center">
                    <div class="col-12 col-lg-3 text-lg-right">
                        <label>Código da Disciplina</label>   
                    </div> 
                    <div class="col-12 col-lg-9">
                        <input class="custom-form-control" id="coddis" name="coddis" type="text" style="max-width:150px;">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button id="btn-addSpecialOfferModal" class="btn btn-default" type="submit">Adicionar</button>
                <button class="btn btn-default" type="button" data-dismiss="modal">Fechar</button>
            </div>
            </form>
        </div>
    </div>
</div>