@component('mail::message')

@component('mail::panel')
<h2>Bienvenido {{$name}}</h2>
<br/>
Has registrado tu email {{$email}} en {{$app}}. <br/> Por favor, haga clic en el enlace de abajo para verificar su cuenta de correo electr&oacute;nico
<br/>

@component('mail::button', ['url' => url('customer/verify', $token), 'color' => 'success'])
Verificar Email
@endcomponent

@endcomponent

@endcomponent
