
<div class="d-flex custom-form-group justify-content-center">
    <div class="col-12 col-md-7 text-left">
        <label for="title">Título*:</label>
    </div>
</div>

<div class="d-flex custom-form-group justify-content-center">
    <div class="col-12 col-md-7">
        <input class="custom-form-control" type="text" name="title" id="title"
            value="{{ old('title') ?? $observation->title ?? ''}}" 
        />
    </div>
</div>


<div class="d-flex custom-form-group justify-content-center">
    <div class="col-12 col-md-7 text-left">
        <label for="body">Corpo*:</label>
    </div>
</div>
<div class="d-flex custom-form-group justify-content-center">
    <div class="col-12 col-md-7">
        <textarea type="text" name="body" id="bodyobservation" style="height: 200px;"/>
            {{ old('body') ?? $observation->body ?? ''}}
        </textarea>
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
            href="{{ route('observations.index') }}"
        >
            Cancelar
        </a>
    </div>
</div>