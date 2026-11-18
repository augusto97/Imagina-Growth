<!DOCTYPE html>
<html lang="es">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ __('Tu panel de creador') }}</title></head>
<body style="margin:0;padding:24px;background:#f9fafb;font-family:system-ui,-apple-system,'Segoe UI',Roboto,Arial,sans-serif;color:#111827;">
<div style="max-width:480px;margin:0 auto;background:#ffffff;border:1px solid #e5e7eb;border-radius:12px;padding:28px;">
    <p style="margin:0 0 8px;font-size:13px;color:#6b7280;">{{ $brand }}</p>
    <h1 style="margin:0 0 12px;font-size:20px;line-height:1.3;">{{ __('Hola, :name', ['name' => $name]) }}</h1>
    <p style="margin:0 0 20px;font-size:15px;line-height:1.55;">{{ __('Aquí puedes ver tus ventas, tus comisiones y el material para compartir. No necesitas contraseña.') }}</p>
    <p style="margin:0 0 20px;"><a href="{{ $url }}" style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;font-weight:600;padding:10px 16px;border-radius:8px;">{{ __('Abrir mi panel') }}</a></p>
    <p style="margin:0;font-size:13px;color:#6b7280;">{{ __('El enlace vale :days días y es solo para ti: no lo compartas.', ['days' => $days]) }}</p>
</div>
</body>
</html>
