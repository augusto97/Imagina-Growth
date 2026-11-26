<?php

use App\Domain\Capture\Http\Public\LandingPageController;
use App\Domain\Capture\Http\Public\WhatsAppLinkController;
use App\Domain\Capture\Http\Webhooks\GoogleLeadFormController;
use Illuminate\Support\Facades\Route;

/*
 * Captación sin tienda (rediseño v2 §11.3, modulos.md M12 y M18). Sin sesión ni CSRF: la página de captura lleva un
 * token firmado de un solo uso y un campo trampa, con límites por IP con hash en el controlador; el webhook de Google
 * se protege con la clave del espacio de trabajo. Van bajo /p/ (y /webhooks/) porque la SPA atiende el resto de las
 * rutas (routes/web.php).
 */

$slug = '[a-z0-9][a-z0-9-]{1,78}[a-z0-9]';

Route::get('p/w/{code}', WhatsAppLinkController::class)->where('code', '[A-Za-z0-9]{7}');
Route::get('p/{slug}', [LandingPageController::class, 'show'])->where('slug', $slug)->name('capture.page');
Route::post('p/{slug}', [LandingPageController::class, 'submit'])->where('slug', $slug);
Route::get('p/{slug}/gracias', [LandingPageController::class, 'thankYou'])->where('slug', $slug);
Route::get('p/{slug}/i/{name}', [LandingPageController::class, 'image'])->where('slug', $slug)->where('name', '(logo|image)-[a-z0-9]{24}\.(jpg|png)');

// Formularios de clientes potenciales de Google Ads: el token de la URL dice el espacio de trabajo; la clave va en el cuerpo.
Route::post('webhooks/google/lead-forms/{token}', GoogleLeadFormController::class)->where('token', 'glf_[A-Za-z0-9]{32}');
