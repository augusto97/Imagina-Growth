<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Imagina Growth</title>
    @vite(['resources/css/app.css', 'resources/js/main.tsx'])
</head>
<body class="h-full">
    <div id="root" class="h-full"></div>
</body>
</html>
