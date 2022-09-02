@extends('laravel-usp-theme::master')

@section('title') 
  @parent 
@endsection

@section('styles')
  @parent
    <link rel="stylesheet" href="{{ asset('css/app.css') }}" />
    <link rel="stylesheet" href="{{ asset('css/listmenu_v.css') }}" />
@endsection

@section('javascripts_bottom')
  @parent
    <script type="text/javascript">
      let baseURL = "{{ env('APP_URL') }}";
    </script>
    <script type="text/javascript" src="{{ asset('js/app.js') }}"></script>
  <script src="https://cdn.tiny.cloud/1/fluxyozlgidop2o9xx3484rluezjjiwtcodjylbuwavcfnjg/tinymce/6/tinymce.min.js" referrerpolicy="origin"></script>
@endsection