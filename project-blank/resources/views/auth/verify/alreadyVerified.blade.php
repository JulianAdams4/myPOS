@extends('layouts.app')

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card">
                <div class="card-header">{{ __('Verificación de Email') }}</div>
                <div class="card-body">

                	<h2>Hola {{$name}}</h2>
					<br/>
					Tu cuenta ya ha sido verificada en {{$app}}.
					<br/>

                </div>
            </div>
        </div>
    </div>
</div>
@endsection