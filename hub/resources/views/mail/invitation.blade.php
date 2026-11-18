<x-mail::message>
# {{ __('Te invitaron a :workspace', ['workspace' => $workspace->name]) }}

{{ __(':name te invitó a trabajar en :workspace con el rol «:role».', ['name' => $inviter->name, 'workspace' => $workspace->name, 'role' => __('roles.'.$role->value)]) }}

<x-mail::button :url="$url">
{{ __('Aceptar la invitación') }}
</x-mail::button>

{{ __('La invitación vence en 7 días. Si no esperabas este correo, puedes ignorarlo.') }}
</x-mail::message>
