@component('mail::message')

@component('mail::panel')
<h2>Bienvenido {{$name}}</h2>
<br/>
Se te ha registrado como administrador de compañia {{ $company }}. <br/> Por favor, haga clic en el enlace de abajo para verificar su cuenta de correo electr&oacute;nico
<br/>

@component('mail::button', ['url' => url('admin/company/verify', $token), 'color' => 'success'])
Verificar Cuenta
@endcomponent

@endcomponent

@endcomponent
