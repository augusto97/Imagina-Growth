<x-mail::message>
# {{ $title }}

@if ($body !== null && $body !== '')
{{ $body }}
@endif

<x-mail::button :url="$url">
{{ __('Abrir en el panel') }}
</x-mail::button>

{{ __('Recibes este aviso de :workspace. Eliges qué avisos te llegan y por dónde en la campana de avisos del panel → «Mis avisos».', ['workspace' => $workspaceName]) }}

{{ $brand->name }}
</x-mail::message>
