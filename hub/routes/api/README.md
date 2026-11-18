Rutas de la API interna de la SPA por módulo. Cada archivo se incluye dentro
del grupo `auth:sanctum` + `ResolveWorkspace` con prefijo `/api/w/{workspace}`
(ver `routes/api.php`). Usa `Gate::authorize('ws.<ability>')` en el controlador.
