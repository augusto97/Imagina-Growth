# Rendimiento del panel (hub)

> Medición y arreglos de noviembre de 2026 sobre la 0.1.10, con las metas de `docs/diseno/rediseno-v2.md` §9
> (API de cada pantalla p95 < 300 ms con la demo, sin N+1; Inicio útil en < 2 s en 4G con caché fría; < 1 s al
> navegar; paquetes por ruta < 150 kB gzip; CLS < 0,1) y de `hub/CLAUDE.md` §16. Las cifras son de una máquina de
> desarrollo (4 vCPU, compartida con otras tareas: hay picos de p95 que son ruido); en el VPS de producción
> (1,6 GB, 2 hilos de PHP) los tiempos absolutos serán mayores, las proporciones se mantienen.

## 1. Metodología

**Datos.** `igr:seed-demo --process` (2.000 clientes, 10.000 pedidos en COP, 4.189 contactos, 1,78 M eventos de
navegación en ClickHouse) y encima, para que ninguna pantalla mida vacía: 6 flujos de la biblioteca con 2.400
recorridos, 12 campañas, 33.600 mensajes salientes en 90 días (600 pedidos atribuidos), 300 conversaciones de
WhatsApp con 12 mensajes cada una, los 17 segmentos del sistema recalculados y las predicciones. Para `/api/me` y
la plataforma, además, una agencia con 40 clientes.

**Servidor.** Como producción: `APP_ENV=production`, sin depuración, cachés de configuración, rutas, eventos y vistas,
OPcache con `validate_timestamps=0`; `php -S` (lo mismo que `artisan serve`, un solo hilo) con un *router* de medición
que agrega a cada respuesta el tiempo en PHP y las consultas de MariaDB (`QueryExecuted`) y de ClickHouse (eventos del
cliente HTTP de `Ch`), y delante un borde de prueba que imita a Caddy (gzip, ETag y `Last-Modified`, y en el «después»
el `Cache-Control` de §4). MariaDB 11, ClickHouse 25.8 y Redis 7 locales.

**API.** Cada endpoint por separado: una petición de calentamiento y 10 seguidas (p50 y p95 medidos en el cliente).
«En frío»: antes de cada petición se borra la caché de la app en Redis (5 veces). «Fallo de minuto»: solo la caché de
1 minuto de los tableros (lo que pasa con tráfico en vivo, `MetricQueryRunner::remember`).

**Navegador.** Playwright con Chromium. Escritorio 1440×900 sin limitar; celular 390×844 con 4G lento (150 ms de RTT,
1,6 Mbps de bajada, 750 kbps de subida) y CPU 4× más lenta. Carga fría (contexto nuevo sin caché, con sesión),
visita repetida (misma pestaña) y navegación dentro de la SPA (clic en el menú desde Inicio). Se toman LCP, CLS, TBT
(suma de tareas largas − 50 ms, aproximado), «listo» (hay contenido y no quedan skeletons: tiempo hasta interactivo
aproximado), peticiones y bytes transferidos.

**Scripts** (fuera del repositorio, en el *scratchpad* de la sesión, `perf/`): `serve.sh`/`stop.sh` (servidor y
borde), `router.php` (contadores), `edge.cjs` (borde tipo Caddy), `capture.cjs` (qué pide cada pantalla),
`endpoints.json` y `api.cjs` (API), `browser.cjs` (navegador), `route-sizes.cjs` y `chunk-sources.cjs` (build),
`compare.cjs` (tablas), `seed-extras.php`, `seed-agency.php`, `setup-user.php` y `fix-extras.php` (datos),
`ch-timeline.sh` (variantes de la consulta del perfil).

## 2. API

### 2.1 Lo que se arregló

| Problema | Dónde | Arreglo |
|---|---|---|
| **Bandeja: una consulta por conversación para su último mensaje, sin índice** (cada una recorría todos los mensajes del espacio: 130 ms × 30) | `InboxQuery::list` | Dos consultas para toda la página (`lastMessages`: la fecha máxima por conversación sale del índice y, con empate, gana el id mayor) e índice `messages (workspace_id, conversation_id, created_at)`, que usan también el chat, el panel, el agente de ventas, la ficha del lead y la reactivación |
| `/api/me`: 5 consultas por espacio de trabajo (rol directo, rol por la cuenta, facturación, marca y dominio del panel) | `MeController`, `Presenters`, `AgencyBranding` | Roles con las dos membresías ya cargadas (`ResolveWorkspace::roleFrom`), facturación en lote (`Presenters::billingFor`) y marca y dominio por cuenta (los clientes de una agencia los comparten) |
| `/api/me`: ~500 ms de CPU en Pennant con muchos espacios (su caché en memoria es una lista que recorre entera en cada lectura: 40 espacios × 14 módulos) | `WorkspaceFeatures::forMany` | Lee los valores ya guardados de la tabla de Pennant con una consulta; lo que falta sigue por `Entitlements::allows`, que lo resuelve y lo guarda como siempre |
| Plataforma: dos consultas de uso por espacio de trabajo | `PlatformController::overview`, `UsageSummary` | `UsageSummary::forMany` (contadores y costo de IA de todos con dos consultas) |
| Reporte de campaña, analítica de flujo y tablero de mensajería por flujo o campaña: recorrían todos los mensajes y pedidos | migración `2026_11_28_300100` | `messages (workspace_id, campaign_id, created_at)`, `messages (workspace_id, flow_id, created_at)`, `orders (workspace_id, attributed_flow_id)` y `orders (workspace_id, attributed_campaign_id)` |
| Tableros de clientes y canales: el primer pedido de cada contacto se buscaba fila por fila (cohortes y valor a 90 y 365 días) | migración `2026_11_28_300100` | `orders (workspace_id, contact_id, is_first_order, created_at)` (cubre el lado del primer pedido; reemplaza a `(workspace_id, contact_id)`, su prefijo): 310 → 70 ms y 240 → 40 ms esas consultas |
| Lista de contactos: ordenaba todos los contactos por última visita | migración `2026_11_28_300100` | `contacts (workspace_id, last_seen_at)` |
| Tableros: cohortes (12 meses), recompra, concentración, valor por canal (25 meses), productos de entrada y comprados juntos se recalculaban con cada periodo, filtro o minuto de tráfico aunque no dependen de ellos | `CustomersDashboard`, `ChannelsDashboard`, `ProductsDashboard` | `MetricQueryRunner::rememberHourly`: una vez por hora (versión por la hora del último evento; respeta `igr.analytics.cache`) |
| Tablero de mensajería: leía los mensajes del periodo seis veces para el total, la tabla por canal y la de flujos y campañas | `MessagingDashboard` | Un desglose por canal y origen (conteos y costo) del que salen las tres; de 9 a 5 lecturas |
| Perfil 360: la línea de tiempo leía `properties` (JSON) de cada bloque con algún evento del contacto (~480 MB para un cliente con dos años) | `ProfileQuery::timeline` | Dos pasos: qué eventos van (columnas livianas) y su detalle por la clave primaria (evento y rango de fechas): ~115 MB |

### 2.2 Antes y después (con la demo; ms; p50 / p95)

Caché caliente (lo normal al navegar):

| Endpoint (pantalla) | Antes | Después | Consultas MariaDB |
|---|---:|---:|---:|
| `inbox/conversations` (Bandeja) | 4.326 / 4.500 | **44 / 48** | 40 → 12 |
| `inbox/conversations/{id}` (chat) | 167 / 167 | **31 / 43** | 10 → 11 |
| `campaigns/{id}/report` | 234 / 267 | **68 / 83** | 14 |
| `flows/{id}/analytics` | 93 / 109 | **61 / 73** | 13 |
| `contacts/{id}/timeline` (Perfil, el cliente con más historia) | 280 / 305 | **187 / 214** | 6 (ClickHouse: 1 → 2, más livianas) |
| `contacts` (lista) | 47 / 68 | **29 / 43** | 5 |
| `contacts/search` (⌘K) | 49 / 68 | **24 / 34** | 4 |
| `/api/me` (agencia con 40 clientes) | 671 / 708 | **40 / 53** | 208 → 14 |
| `/api/me` (un espacio) | 41 / 45 | 29 / 35 | 15 → 14 |
| `getting-started`, `overview` (Inicio) | 81 / 97, 81 / 99 | 77 / 92, 74 / 85 | 9, 11 |
| `sites/{id}` | 105 / 113 | 97 / 116 | 6 |
| `segments/preview` | 47–67 / 50–72 | 44–72 / 53–94 | 13 |
| Resto (segmentos, flujos, campañas, hallazgos, canales, plataforma, tableros en caché) | 15–48 | 14–41 | sin cambios |

Tableros de Analítica sin caché (primera vez, o el minuto siguiente con tráfico):

| Tablero | Antes (frío) | Después (frío) | Después (fallo de minuto, lo de cada hora ya en caché) |
|---|---:|---:|---:|
| Mensajería | 485 / 512 | 318 / 735\* | 303 / 346 |
| Canales | 398 / 475 | 213 / 235 | 79 |
| Clientes | 384 / 479 | 151 / 527\* | 58 |
| Productos | 313 / 365 | 324 / 705\* | 76 |
| Resumen (30 días / 12 meses) | 100 / 197 | 116 / 198 | 62 / 73 |
| Embudo, búsquedas, geografía, cupones, rentabilidad, servicios, «¿cómo nos conociste?» | 28–75 | 27–89 | 27–98 |

\* Picos de p95 por otras tareas que corrían en la misma máquina; el p50 es el representativo.

Plataforma con 41 espacios: `GET /api/platform/overview` de 96 consultas y 95 ms a 16 consultas y 44 ms (ya no crece con la cantidad de espacios).

**Meta p95 < 300 ms:** se cumple en todas las pantallas con caché caliente y en los tableros en el caso de todos los
días (fallo de minuto), salvo Mensajería, que queda en ~300 ms sin caché (antes ~490; §7.2, 1). Productos pasa de
300 ms solo la primera vez de cada hora (comprados juntos; §7.2, 4).

### 2.3 Pruebas que lo fijan

`tests/Feature/Performance/QueryBudgetTest.php`: la bandeja con 2 o 12 conversaciones, `/api/me` con 3 o 12 espacios
(directos y por la agencia, con el rol y la facturación de cada uno) y la plataforma con 2 u 8 espacios hacen las
mismas consultas, y cada una devuelve lo correcto (el último mensaje de cada conversación con empate de fecha, el rol
`admin` por la agencia, el estado de facturación de cada cuenta, el uso del mes). `tests/Feature/SpaRuntimeTest.php`
fija las precargas del HTML (§3.3).

## 3. SPA: build y arranque

### 3.1 Qué se baja al entrar (gzip nivel 9)

| Archivo | kB gzip | Qué es |
|---|---:|---|
| `locale` | 120 | Todos los textos de es-CO (y lo que cambia en es-MX y es-VE): 355 kB sin comprimir |
| `react` | 66 | react-dom |
| `ui` | 64 | Radix, cmdk, íconos de lucide, tailwind-merge |
| `data` | 58 | React Router, TanStack Query, i18next, Zustand |
| `forms` | 35 | zod y react-hook-form: los trae `LoginPage`, que es la única pantalla que no carga perezosa |
| `main` + otros | 25 | Menú, guardas, rutas, utilidades |
| CSS | 13 | Tailwind |
| **Arranque** | **381** | antes 380 (el mismo código en 13 archivos en vez de 12) |

Por pantalla (lo que se suma al arranque): Inicio 136 kB (incluye `charts`, Recharts, 117 kB), Perfil 153, Analítica
146, constructor de flujos 132 (React Flow), Bandeja 44, Plataforma 29, el resto entre 2 y 19; el editor de correo
(GrapesJS + MJML, 637 kB) solo al abrir una plantilla de correo. Ninguna pantalla trae las librerías pesadas de otra.

**Cambio en `vite.config.ts`:** los textos en su propio chunk (`locale`). El código de la app pesa 19 kB gzip sin
ellos: una versión que no cambie textos ya no hace bajar de nuevo los 120 kB (y al revés), y el navegador los baja
en paralelo. Se probó sacar los íconos y los componentes que no usa el arranque del grupo `ui` (con `tags:
['$initial']`): el arranque baja 13 kB pero cada pantalla sube lo mismo, así que no se dejó. **Bajar de verdad el
arranque necesita tocar rutas y pantallas** (§7.1, 1 a 3).

### 3.2 TanStack Query (`resources/js/app/queryClient.ts`)

- `staleTime` 15 s → **60 s**: volver a una pantalla o a la pestaña dentro del minuto pinta lo que hay en memoria sin
  repetir sus 3 a 7 consultas (con 2 hilos de PHP en el servidor pequeño, cada ráfaga cuenta). Lo que tiene que estar
  al día ya lo dice su consulta (la bandeja con `refetchInterval`, `/api/me` y el panel con `staleTime` propio) y cada
  mutación invalida lo que cambia.
- `gcTime` 5 → **10 min**: al volver a una pantalla se ve lo último mientras se actualiza, sin skeleton.
- Se mantienen `refetchOnWindowFocus` (solo lo vencido) y los reintentos (2, nunca en 4xx).

### 3.3 HTML de la SPA (`resources/views/app.blade.php`)

- **Precarga de la fuente** (`SpaRuntime::fontPreloads`): el subconjunto latino de Inter (48 kB) se pide junto con el
  CSS y no cuando React pinta el primer texto. Sale del manifiesto del build (si el sistema visual cambia de fuente
  variable, toma la nueva; sin build no precarga nada).
- **Precarga de `/api/me`** (solo con sesión): la petición que la SPA hace antes de pintar nada sale en paralelo con
  el código en vez de después de que corra (en cada carga fría se ahorra la espera de esa petición: un viaje de ida y
  vuelta, 150 ms en 4G lento, más lo que tarda `/api/me`). Se comprobó que la SPA usa la precarga (una sola petición).

## 4. Servido y caché (infra)

`infra/config/frankenphp/Caddyfile`: los archivos de `/build/assets/*` (llevan el hash en el nombre) salen con
`Cache-Control: public, max-age=31536000, immutable`, **solo si el archivo existe** (el 404 de un hash viejo, con una
pestaña abierta durante una actualización, no se guarda). Antes no llevaban `Cache-Control`: el navegador y Cloudflare
aplicaban una frescura heurística (`Last-Modified`) y después de un rato revalidaban cada archivo (13 en el arranque,
de 4 a 20 más por pantalla): en el celular, una ida y vuelta por archivo. El HTML sigue con `no-cache, private`
(Laravel). La compresión ya la hacía el Caddy del borde (`encode zstd gzip`). `docs/despliegue.md` §1 lo dice.

## 5. Navegador: antes y después

Mediana de 3 cargas frías por pantalla. «Listo»: hay contenido y no quedan skeletons. En escritorio la diferencia de
±30 % entre corridas en las pantallas que no cambiaron es ruido de la máquina (otras tareas pesadas en paralelo):
los bytes son los mismos y la API igual o más rápida.

**Celular, 4G lento y CPU 4×:**

| Pantalla | LCP antes → después | Listo antes → después | CLS | TBT antes → después | Transferido |
|---|---:|---:|---:|---:|---:|
| Inicio | 4.308 → 4.280 | 4.959 → 4.825 | 0,257 | 882 → 648 | 586 → 589 kB |
| Contactos | 3.132 → 3.232 | 3.441 → 3.516 | 0,216 | 329 → 295 | 448 → 450 kB |
| Perfil 360 | 5.088 → 5.128 | 5.313 → 5.260 | 0,207 | 1.108 → 1.049 | 605 → 608 kB |
| Segmentos / segmento | 3.552 / 3.568 → 3.588 / 3.692 | 3.543 / 3.733 → 3.577 / 3.824 | 0 | ~350 | 452–465 kB |
| Flujos / constructor | 3.236 / 3.932 → 3.236 / 4.116 | 3.471 / 3.929 → 3.533 / 4.107 | 0 | ~300 | 456–580 kB |
| Campañas | 3.300 → 3.416 | 3.612 → 3.712 | 0,018 → 0 | 410 → 389 | 451 kB |
| **Bandeja** | 3.504 → 3.548 | **8.809 → 3.843** | 0 | 437 → 414 | 487 kB |
| Analítica / tablero de ventas | 4.100 / 4.076 → 4.264 / 4.292 | 4.915 / 4.077 → 4.919 / 4.292 | 0 | 996 / 389 → 807 / 420 | 590 kB |
| Hallazgos | 3.260 → 3.332 | 3.639 → 3.593 | 0,308 | 424 → 363 | 448 kB |
| Canales, sitios, plataforma | 3.164–3.692 → 3.356–3.636 | 3.348–3.687 → 3.511–3.677 | ≤ 0,02 | ~300–480 | 445–474 kB |

Primera navegación desde Inicio a cada pantalla (chunk + API): Bandeja **5.161 → 815–1.044 ms**; el resto entre 230 y
720 ms antes y después (cada pantalla espera su chunk y sus consultas; ver §7.1, 5). Las siguientes visitas a la
misma pantalla: 70–115 ms sin peticiones (datos en memoria).

**Escritorio sin limitar:**

| Pantalla | LCP antes → después | Listo antes → después |
|---|---:|---:|
| **Bandeja** | 356 → 436 | **4.812 → 523** |
| Inicio | 672 → 812 | 665 → 801 |
| Perfil 360 | 528 → 636 | 800 → 856 |
| Resto | 284–548 → 304–556 | 294–529 → 362–626 |

Navegación: Bandeja **4.496 → 212–234 ms**; el resto 76–237 → 117–281 ms la primera vez y 21–170 ms después.

**Lo que dicen las cifras:** la carga fría en el celular la decide el peso (~450–600 kB gzip a 1,6 Mbps son 2,5–3,5 s
antes de pintar nada útil) y la CPU (TBT de 300 a 1.100 ms): nada de lo que se cambió aquí baja esos bytes, porque
lo pesado del arranque son los textos, `LoginPage` y las gráficas, y moverlos toca rutas y pantallas (§7.1, 1 a 3).
Lo que sí cambió en el navegador: la Bandeja dejó de esperar 4 s a la API; `/api/me` se pide una sola vez y en
paralelo con el código (se comprobó que la SPA usa la precarga: una sola petición); la fuente llega con el CSS (se
pide solo el subconjunto latino); y en producción los assets ya no se revalidan (§4). El CLS de Inicio, Contactos,
Perfil y Hallazgos no viene de la fuente sino del contenido que llega después (§7.1, 4).

## 6. Backend general (revisado)

- **Producción ya está bien en lo básico:** `growth-update` corre `config:cache`, `route:cache`, `view:cache` y
  `event:cache`; OPcache encendido con `validate_timestamps=0` y 256 MB; sesión y caché en Redis (la caché en su propia
  instancia, `allkeys-lru`); colas en Redis con Horizon y el perfil pequeño de procesos.
- **Arranque por petición:** ~15 ms de PHP en `/up` (sin consultas): 30 proveedores de módulo, Fortify, Horizon,
  Pennant, Sanctum, MCP. Los ajustes de la plataforma se leen de Redis (una ida en el arranque y otra en
  `RefreshPlatformSettings`); el middleware de espacio de trabajo hace 3 consultas (persona, espacio, membresía). No
  hay consultas pesadas en el arranque. Lo que más bajaría este piso es el modo *worker* (§7.2, 2).
- `GET inbox/metrics` sin `?days=` responde 500 (`validate()` no devuelve la clave): la SPA siempre la manda, pero un
  cliente de la API no.

## 7. Recomendaciones pendientes (priorizadas)

### 7.1 SPA (tocan rutas, pantallas o el menú: para el rediseño)

1. **Textos por módulo con su pantalla** (−100 kB gzip del arranque, ~−0,5 s en 4G lento). Hoy `lib/i18n.ts` mete
   los 39 archivos de `locales/es-CO/` en el arranque. El arranque necesita solo `es-CO.json` (menú, comunes: ~10 kB
   gzip). Cada `lazy` de `features/*/routes.tsx` puede esperar sus textos junto con la pantalla
   (`await Promise.all([import('./Página'), cargarTextos('contactos')])`, con `i18n.addResourceBundle(lng,
   'translation', textos, true, true)`), y lo que usa el menú o ⌘K de otro módulo pasa a `es-CO.json`.
2. **`LoginPage` perezosa** en `app/router.tsx` (como las demás): saca zod y react-hook-form (35 kB gzip) del arranque
   de quien ya tiene sesión.
3. **Gráficas perezosas dentro de Inicio, Perfil y Analítica** (Recharts, 117 kB gzip): primero los KPI y el texto,
   la gráfica cuando llega su chunk (o al entrar en pantalla). Inicio pasaría de 136 a ~20 kB.
   Con 1 a 3 el Inicio en frío baja de ~517 a ~265 kB gzip antes de pintar (la gráfica llega después): es lo que hace
   falta para acercarse a «< 2 s en 4G».
4. **Saltos de diseño (CLS)** medidos: Inicio 0,125 (escritorio) y 0,257 (celular), Contactos 0,216, Perfil 0,207 y
   Hallazgos 0,308 (celular). Vienen de contenido que llega después y empuja lo de abajo (las rejillas de KPI y las
   tarjetas de Inicio, la barra de filtros de Contactos, avisos que aparecen arriba de Hallazgos). Skeletons con la
   altura final, avisos con su espacio reservado (o debajo) y filtros pintados desde el primer render.
5. **Precarga al pasar el puntero o enfocar** un enlace del menú (`queryClient.prefetchQuery` de las consultas de la
   pantalla y el `import()` de su chunk): hoy la primera visita a cada pantalla espera chunk + API (150–250 ms en
   escritorio, 250–650 ms en 4G).
6. **Peticiones duplicadas** al abrir la Bandeja (`inbox/conversations` dos veces y, con un chat abierto, también el
   chat y el panel) y Canales (`/channels` dos veces): revisar las claves y las invalidaciones de esas pantallas.
7. **La paleta ⌘K (cmdk) y los diálogos del menú** pueden cargar al abrirse (unos 15 kB del grupo `ui`).
8. **Consola limpia:** React Router avisa «No `HydrateFallback` element provided» en cada carga (rutas perezosas sin
   `HydrateFallback`; `app/router.tsx`).

### 7.2 Backend

1. **Tablero de mensajería** (~300 ms sin caché): un resumen diario por canal y origen (como `message_archive_daily`)
   para los días cerrados, y solo hoy desde `messages`.
2. **FrankenPHP en modo *worker*** (Laravel Octane): el arranque por petición baja de ~15 ms a ~2 ms. Antes, revisar el
   estado entre peticiones (singletons, estáticas, `CurrentWorkspace` es *scoped*).
3. **OPcache:** revisar `opcache_get_status()` en producción (`interned_strings_usage`, `cache_full`) y, si se llena,
   subir `opcache.interned_strings_buffer` (8 MB por defecto; con Laravel y sus paquetes suele quedarse corto) en
   `infra/config/php/90-growth.ini`.
4. **Cohortes, comprados juntos y productos de entrada** a las tablas del cálculo nocturno, como pide
   `hub/CLAUDE.md` §10 (hoy en caché por hora).
5. **Línea de tiempo del perfil** (~190 ms para el cliente con más historia): una proyección en ClickHouse ordenada
   por contacto (vista materializada por `contact_id` y `anonymous_id`) la llevaría a pocos ms.
6. **Primeros pasos** (~60 ms de SQL): la venta recuperada con `OR id IN (subconsulta)` no usa índices; partirla en dos
   consultas.
