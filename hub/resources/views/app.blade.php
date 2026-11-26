<!DOCTYPE html>
<html lang="es-CO">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Imagina Growth</title>
    {{-- Configuración del servidor (Reverb y versión): el build de la SPA es el mismo para todas las instalaciones. --}}
    <script>window.igrRuntime = @json(\App\Support\SpaRuntime::config());</script>
    {{-- Rendimiento (docs/rendimiento.md §4): la fuente y /api/me se piden en paralelo con el código, no después de que corra. --}}
    @foreach (\App\Support\SpaRuntime::fontPreloads() as $font)
    <link rel="preload" as="font" type="font/woff2" href="{{ $font }}" crossorigin>
    @endforeach
    @auth
    <link rel="preload" as="fetch" href="/api/me" crossorigin>
    @endauth
    @vite(['resources/css/app.css', 'resources/js/main.tsx'])
</head>
<body>
    <div id="root"></div>
</body>
</html>
