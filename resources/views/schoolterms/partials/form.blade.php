<div class="row custom-form-group justify-content-center">
    <div class="col-sm-2  text-sm-right" style="min-width:150px">
        <label for="year">Ano *</label>
    </div>
    <div class="col-sm-2" style="min-width:150px">
        <input class="custom-form-control" type="text" name="year" id="year"
            value='{{ $periodo->year ?? ""}}'
        />
    </div>
</div>

<div class="row custom-form-group justify-content-center">
    <div class="col-sm-2  text-sm-right" style="min-width:150px">
        <label for="period">Período *</label>
    </div>
    <div class="col-sm-2" style="min-width:150px">
        <select class="custom-form-control" type="text" name="period"
            id="period"
        >
            <option value="" {{ ( $periodo->period) ? '' : 'selected'}}></option>

            @foreach ([
                        '1° Semestre',
                        '2° Semestre',
                     ] as $period)
                <option value="{{ $period }}" {{ ( $periodo->period === $period) ? 'selected' : ''}}>{{ $period }}</option>
            @endforeach
        </select>
    </div>
</div>

<div class="row custom-form-group justify-content-center">
    <div class="col-sm-2  text-sm-right" style="min-width:150px">
        <label for="period">Data Maxima de Reserva *</label>
    </div>

    <div class="col-sm-2" style="white-space: nowrap;">
        <input class="custom-form-control custom-datepicker" style="max-width:200px;"
            type="text" name="dtamaxres" id="dtamaxres" autocomplete="off"
            value="{{ old('dtamaxres') ?? $periodo->dtamaxres ?? ''}}"
        />
    </div>
</div>

<div class="row custom-form-group justify-content-center">
    <div class="col-sm-6 text-center text-sm-right my-1">
        <button type="submit" class="btn btn-outline-dark">
            {{ $buttonText }}
        </button>
    </div>
    <div class="col-sm-6 text-center text-sm-left my-1">
        <a class="btn btn-outline-dark"
        href="{{ route('schoolterms.index') }}"
        >
            Cancelar
        </a>
    </div>
</div>