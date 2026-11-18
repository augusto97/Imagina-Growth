<!DOCTYPE html>
<html lang="es-CO">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Imagina Growth</title>
    {{-- Configuración del servidor (Reverb y versión): el build de la SPA es el mismo para todas las instalaciones. --}}
    <script>window.igrRuntime = @json(\App\Support\SpaRuntime::config());</script>
    @vite(['resources/css/app.css', 'resources/js/main.tsx'])
</head>
<body>
    <div id="root"></div>
</body>
</html>
