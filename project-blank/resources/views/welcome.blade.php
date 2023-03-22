<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- CSRF Token -->
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <!-- PUSHER TOKEN -->
    <meta name="pusher_token" content="{{ config('app.pusher_key') }}">
    <title>Panel myPOS</title>
    <!-- Styles -->
    <link href="{{ mix('css/app.css') }}" rel="stylesheet">
    <script type="text/javascript">
        window.APP_DEBUG = {{ config('app.debug') ? 'true' : 'false' }};
    </script>
</head>
<body>
    <div id="root" class="app"></div>
    <div id="example" class="example"></div>
    <script src="{{ mix('js/app.js') }}"></script>
</body>
</html>


{{-- <!doctype html>
<html lang="{{ app()->getLocale() }}">
    <head>
        <meta charset="utf-8">
        <meta http-equiv="X-UA-Compatible" content="IE=edge">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Blanquito App</title>
        <link href="{{asset('css/app.css')}}" rel="stylesheet" type="text/css">
        <!-- CSRF Token -->
        <meta name="csrf-token" content="{{ csrf_token() }}">
    </head>
    <body class="app header-fixed sidebar-fixed aside-menu-fixed aside-menu-hidden">
        <div id="root"></div>
        <script src="{{ asset('js/app.js') }}"></script>
    </body>
</html> --}}