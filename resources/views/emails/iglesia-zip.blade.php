@component('mail::message')

@slot('header')
    @component('mail::header', ['url' => config('app.url')])
        {{-- Intencionalmente vacío --}}
    @endcomponent
@endslot

# Codigos de entrada para la fesja Cuenta Regresiva!!

**Distrito:** {{ $distrito }}
**Iglesia:** {{ $iglesia }}

{{ $bodyText }}

Gracias,<br>
FESJA-BN

@endcomponent
