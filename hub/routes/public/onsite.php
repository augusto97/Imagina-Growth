<?php

use App\Domain\Onsite\Http\Public\OnsitePublicController;
use Illuminate\Support\Facades\Route;

/*
 * M12, captación en el sitio (api-connector.md §8.3). `widgets.json` lo sirve
 * PublicWidgetsController (routes/ingest.php) junto con la encuesta; el OPTIONS de
 * CORS de /p/* también vive allá. El doble opt-in va bajo /p/ porque la SPA atiende
 * las demás rutas (routes/web.php).
 */

Route::get('p/{publicKey}/visitor.json', [OnsitePublicController::class, 'visitor']);
Route::post('p/{publicKey}/widgets/{widgetId}/submit', [OnsitePublicController::class, 'submit']);
Route::get('p/{publicKey}/confirmar/{token}', [OnsitePublicController::class, 'confirm'])->where('token', '[A-Za-z0-9_\-]+\.[a-f0-9]{32}');
