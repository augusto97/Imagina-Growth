# Historial de la especificación y de las fases

## 2026-11-20 — Versión 0.1.6: auditoría, primer lote (seguridad de la ingesta y memoria de Redis)

Primer lote de `docs/auditoria-2026-11.md`, con lo que podía perder datos o llenar el servidor:
- **S1 (crítico):** la ruta pública del tracker aceptaba pedidos, clientes, formularios y reseñas con la clave pública
  que está en el HTML. Ahora solo acepta eventos de navegación (`not_allowed` para el resto), el worker descarta los
  que ya estaban en la cola, una fecha del futuro pasa a la hora de recepción y del navegador nada de hace más de 7
  días (`stale`).
- **R1:** el stream de ingesta crecía sin límite en Redis (XACK no borra). El worker recorta lo confirmado cada 5 s,
  el tope baja a 250.000 por shard, el dedupe a 4 días, Redis de colas tiene `maxmemory` (20% de la RAM) sin
  instantáneas RDB y `growth:monitor` avisa al 85%.
- **R2 y R3:** un error de datos (`HY000`) se trataba como pasajero y el lote se reintentaba para siempre; ahora se
  reintenta uno por uno y el evento que falla 5 veces va a los rechazos. Con ClickHouse caído, espera creciente.
- Incluye el enlace de invitación para copiar y el aviso de correo apagado.
- Pruebas: 6 nuevas (lista de eventos del navegador, fechas, segunda barrera, recorte del stream, worker con el
  stream vacío al final y evento dañado) y la suite completa del hub en verde.

## 2026-11-20 — Invitaciones con enlace para copiar y aviso de correo apagado

En producción `MAIL_MAILER` era `log`: las invitaciones a los clientes nunca salían y nadie lo sabía.
- Al crear un cliente, invitar a una persona desde la ficha o dar de alta un cliente desde la agencia, el panel
  muestra el enlace de la invitación con un botón para copiarlo.
- La plataforma avisa cuando el correo no está configurado, con el ejemplo de variables para SES o SMTP, y, cuando sí
  lo está, permite enviar un correo de prueba que muestra el error exacto del proveedor si falla.
- Pruebas: enlace devuelto en las tres altas, estado del correo y prueba de envío (éxito y error).

## 2026-11-20 — Versión 0.1.5: administración de clientes y planes

El dueño no encontraba dónde crear planes ni clientes, y tenía razón: los planes solo existían en
`config/igr-plans.php` y nunca se cargaban en producción (`igr:plans:sync` no lo corría nadie, así que el selector de
plan salía vacío), y no había forma de dar de alta un cliente ni desde la plataforma ni desde la agencia.
- **Plataforma → Clientes:** «Nuevo cliente» crea la empresa (negocio o agencia), su primer espacio de trabajo, le
  asigna el plan y envía la invitación al dueño; la ficha muestra espacios, sitios, personas e invitaciones
  pendientes, y permite editar datos, agregar espacios e invitar personas. El superadmin no queda dentro del espacio
  del cliente.
- **Plataforma → Planes:** crear, editar, archivar y restaurar planes (nombre, métrica, volumen, precios COP/USD,
  módulos, límites, visibilidad). Al editar, los clientes de ese plan se actualizan. La migración carga los planes por
  defecto si la tabla está vacía.
- **Agencia → Clientes:** «Nuevo cliente» crea el espacio del cliente en la cuenta de la agencia y, opcional, invita a
  su dueño.
- Pruebas: 15 nuevas (permisos, planes, alta de clientes, invitación de dueño de agencia, alta desde la agencia) y las
  113 de facturación, agencia, accesos y aislamiento siguen en verde. Revisado en el navegador.

## 2026-11-19 — Versión 0.1.4: todo en un solo dominio

En el primer sitio real el plugin no podía enviar datos: el hub le había dado `https://in.growth.imagina.cloud` y ese
registro DNS nunca se pidió en el instalador. Era un requisito escondido, así que se quitó:
- La ingesta, los enlaces y el destino del CNAME de los dominios propios van por defecto en el dominio del panel; el
  Caddyfile de la plantilla manda `/v1` y `/p` del dominio principal a las réplicas de ingesta y ya no tiene los
  bloques `in.` ni `go.`. El único registro DNS es el del dominio.
- `install.sh` pasa al dominio principal las URLs de instalaciones anteriores cuyos subdominios no existen en el DNS
  y avisa al final si falta el DNS de algún nombre en uso.
- Plugin 0.1.2: si el host de ingesta no existe en el DNS (`cURL error 6`), pasa al dominio principal del hub, lo
  guarda y reintenta, sin reconectar el sitio. Los eventos pendientes salen solos.

Probado en Ubuntu 26.04 limpio: la ingesta responde en el dominio principal (`/v1/s` 401 sin firma, `/v1/b` 400,
`/p` llega a la app) y, con la configuración vieja (`in.`, `go.`, `domains.` sin DNS), el instalador la corrige y la
ingesta sigue respondiendo. En el plugin, la prueba del caso real envía al dominio principal tras el error de DNS.

## 2026-11-19 — Plugin 0.1.2 y aviso de DNS en el instalador

- En el primer sitio real, el plugin no podía enviar nada: faltaba el registro DNS de `in.<dominio>` (la ingesta) y
  el error era `Could not resolve host`. `install.sh` ahora avisa al final qué nombres (`dominio`, `in.`, `go.`) no
  tienen registro DNS.
- Plugin 0.1.2: el total de categorías de la importación usa la misma consulta que recorre la importación (en ese
  sitio el panel mostraba «8 / 4»; `wp_count_terms()` pasa por filtros de otros plugins).

## 2026-11-19 — Versión 0.1.3: «Importar y enviar ahora» y descarga del plugin desde el panel

- **Plugin 0.1.1:** en «Estado», el botón **«Importar y enviar ahora»** hace la importación histórica y el envío
  desde la página del administrador cuando el cron de WordPress no corre (antes la importación quedaba «en cola» sin
  avanzar y no había cómo forzarla). Muestra el avance por entidad y el error exacto del hub. También avisa en
  «Estado» si las tareas programadas van atrasadas. Probado en el navegador con `DISABLE_WP_CRON`: 250 pedidos,
  69 productos, clientes, reembolsos y reseñas importados y enviados en 2 s al hub de prueba; con el hub caído, se
  detiene y muestra el error de conexión.
- **Hub:** botón **«Descargar plugin»** en Sitios (y en el paso 1 de «Conectar con un código»). El paquete de cada
  versión trae el plugin en `public/downloads/imagina-growth-connector.zip`.

## 2026-11-19 — Versión 0.1.2: releases automáticos

Publicar ya no requiere entrar a GitHub: un push que sube `VERSION` crea el tag y el release con sus assets
(`release.yml`, `docs/despliegue.md` §3). 0.1.2 es la primera versión publicada así y la primera que trae el plugin
de WordPress como asset; la app no cambia respecto de 0.1.1.

## 2026-11-19 — El plugin se publica con cada versión

Hasta ahora el plugin «Imagina Growth Connector» no se publicaba en ningún lado. `connector/bin/build-zip.sh` arma
el zip instalable (sin dependencias de desarrollo; el plugin trae su propio autoload) y `release.yml` lo sube a cada
release como `imagina-growth-connector.zip`. Probado: se instala y activa con WP-CLI en WordPress 6 con WooCommerce
11 y su página de ajustes carga sin errores. Imagina Updater sigue pendiente (connector/CLAUDE.md §15).

## 2026-11-19 — Versión 0.1.1: perfil pequeño para servidores con poca RAM

Corrige la entrada anterior: pedir 4 GB como mínimo era un error. Una instalación nueva, sin sitios ni tráfico, no
necesita esa memoria; lo que pasaba es que todo se configuraba para volumen de producción sin mirar el servidor
(ClickHouse sin límite, ~20 procesos de Horizon, 4 workers de ingesta). Ahora `install.sh` elige el perfil por la
RAM (`docs/despliegue.md` §7): con menos de ~3,5 GB, ClickHouse al 25 %, MariaDB con 1/8, un worker de ingesta para
los 4 shards y Horizon con 3 procesos (`IGR_WORKERS_PROFILE=small`, `config/horizon.php`). Solo se detiene con menos
de 1,4 GB. `growth-update` lee de `updater.conf` qué workers de ingesta reinicia. `SendMessageJob` y
`ClassifyInboundMessage` declaran sus intentos (5 y 3) para no depender del supervisor.

Probado en Ubuntu 26.04 con 1,6 GB de RAM, 2 GB de swap y 2 núcleos (como el servidor real): con la configuración
anterior la memoria se llenaba y la app respondía 503; tras correr el instalador nuevo y actualizar a 0.1.1, sin
procesos muertos por memoria en la medición, `/health/ready` y `/login` en 10 a 30 ms, y en el navegador el registro
(0,5 s) y `/seguridad` (0,7 s) sin errores. Si la memoria ya está llena, hay que apagar la app antes de correr el
instalador.

## 2026-11-19 — Memoria: mínimo de RAM y límites de ClickHouse

La primera instalación real fue en un servidor de 1,6 GB de RAM: ClickHouse tomaba la memoria, el kernel lo mataba
cada minuto y el panel dejaba de responder. `install.sh` ahora:
- se detiene antes de instalar si el servidor tiene menos de ~3,5 GB (pide 4 GB; recomienda 8 GB) y avisa por
  debajo de ~7,5 GB;
- limita ClickHouse al 30 % de la RAM, con cachés proporcionales y sin los logs de sistema que la app no usa
  (`config.d/growth-memory.xml`, reescrito en cada corrida para que ampliar el servidor ajuste los límites).

Probado en Ubuntu 26.04: ClickHouse toma los límites (`system.server_settings`), solo quedan `query_log` y
`part_log`, y la app responde sana.

## 2026-11-19 — `install.sh` también para Ubuntu 26.04 LTS

El servidor de producción usa Ubuntu 26.04. Ahí los repositorios oficiales no publican MariaDB 11.4 ni Redis 7, así
que `install.sh` elige por sistema: en 24.04 sigue con MariaDB 11.4 y Redis `7.4.*`; en 26.04 usa MariaDB 11.8 (LTS)
y Redis `8.*`. ClickHouse 25.8, FrankenPHP con PHP 8.4 y Caddy no cambian. La app no cambió.

Probado en un Ubuntu 26.04 limpio con systemd 259 (`GROWTH_IT_UBUNTU=26.04 infra/tests/install/run.sh`): instaló la
versión 0.1.0 publicada en GitHub (sin firma, con sha256), los 17 servicios quedaron activos, `/health/ready` en
8080/8081/8082 y Caddy respondieron, la segunda corrida no cambió nada, y las copias de MariaDB y ClickHouse y la
prueba de restauración salieron bien.

## 2026-11-19 — La firma de las versiones pasa a ser opcional

Para la primera instalación se quiere actualizar como con las otras apps: publicar un release y pulsar
«Instalar», sin crear llaves. La firma ed25519 queda para después.

- `release.yml` ya no falla sin el secreto `GROWTH_RELEASE_SIGNING_KEY`: publica zip, `.sha256`, `release.json` e
  `install.sh`, y verifica el sha256. Con el secreto, firma igual que antes.
- `install.sh` y `growth-update` validan solo el sha256 si el servidor no tiene
  `/etc/growth/release-signing.pub.pem`. Si la tiene, la firma se exige (una versión sin `.sig` no se instala).
- Pruebas del actualizador: versión sin firma sin llave (se instala), zip alterado sin llave (se rechaza por
  sha256) y versión sin firma con llave (se rechaza).

## 2026-11-18 — Despliegue nativo sin Docker, con versiones firmadas y actualización desde el panel

La app todavía no estaba instalada en ningún lado, así que el despliegue pasó directo de Docker Compose a
servicios nativos de systemd. El contrato completo está en `docs/despliegue.md`; la lógica de Laravel, la SPA,
el plugin y el ML no cambió.

### Qué cambió
- **Sin Docker:** se borraron los `docker-compose` de local y producción, el Dockerfile del hub y el del ML, y las
  referencias en la documentación y los scripts. En el CI quedan los service containers de GitHub Actions para
  las pruebas.
- **`install.sh`** (Ubuntu 24.04, idempotente):
  - instala desde los repositorios oficiales: MariaDB 11.4, ClickHouse 25.8, Redis 7.4 en dos instancias
    (colas con `noeviction` y AOF; caché con `allkeys-lru`), FrankenPHP con PHP 8.4 y sus extensiones, uv y Caddy;
  - crea el usuario `growth`, `/opt/growth` (`releases/`, `current`, `shared/`), el `.env` con secretos nuevos,
    las bases, las unidades de systemd agrupadas en `growth.target` y el firewall;
  - instala la última versión verificada.
- **Paquete de versión:** al publicar un release, `release.yml` arma el zip (`composer install --no-dev`, la SPA
  compilada sin `node_modules`, el `uv.lock` del ML) con su `.sha256` y una firma ed25519; la llave pública va
  incrustada en `install.sh`.
- **Actualización desde el panel** (`/plataforma` → «Actualizaciones»). El panel solo deja el pedido;
  `growth-update`, lanzado con `systemd-run`, hace en orden:
  1. revisa el espacio en disco y los inodos;
  2. verifica la firma y el sha256;
  3. extrae y enlaza `shared/`;
  4. arma las cachés;
  5. copia MariaDB;
  6. migra MariaDB y ClickHouse;
  7. cambia el symlink y reinicia de a uno;
  8. revisa la salud con la versión;
  9. vuelve atrás si falla;
  10. deja 3 versiones.
- **Seguridad:** las versiones son de root y de solo lectura para la app. Los scripts de root viven en
  `/usr/local/lib/growth` y solo se reemplazan con los de una versión verificada que pasó el health-check.
- **En la app:**
  - `/health/ready` con la versión;
  - Reverb configurado en tiempo de ejecución (`window.igrRuntime`);
  - la caché en otra instancia de Redis;
  - recarga única ante `vite:preloadError`;
  - el ML con `pyproject.toml` y `uv.lock`.
- **Copias y vigilancia:**
  - `mariadb-backup` y el `BACKUP` nativo de ClickHouse al Storage Box con `rclone`, más una prueba semanal que
    los restaura en bases temporales;
  - `growth:monitor` con heartbeats de Better Stack, incluido el atraso de los streams.
- **Desarrollo local sin Docker:** `infra/dev/setup-local.sh`.

### Prueba final (`infra/tests/install/e2e-update.sh`)
Ubuntu 24.04 limpio con systemd, en un contenedor desechable solo para verificar, con versiones firmadas con una
llave de prueba y servidas por un simulador de la API de GitHub Releases.

| Paso | Resultado |
|---|---|
| `install.sh` en el servidor limpio | 0.1.0 activa con 17 unidades `growth`; `/health/ready` ok en 8080, 8081 y 8082 |
| `install.sh` otra vez | Ningún cambio: `.env`, `updater.conf`, Caddyfile y versión iguales |
| 0.2.0 desde el panel (navegador, administrador con 2FA) | Instalada: corrió la migración nueva, se agregó la variable nueva al `.env` sin tocar `APP_KEY`, hubo copia de la base antes de migrar y se actualizaron los scripts de root. **122 pedidos al panel y a la ingesta durante el cambio, ninguno falló** |
| 0.3.0 rota (`/health/ready` responde 503) | La primera réplica de ingesta no respondió con la versión nueva: el servidor volvió solo a 0.2.0 y el panel lo mostró con cada paso y el historial |

### Errores que encontraron las pruebas (corregidos con prueba)
- **«Buscar ahora» respondía 500** en un servidor real. Llamaba al comando con Artisan, y en la web los comandos
  de consola no están registrados (en las pruebas, que corren en consola, funcionaba).
- **El panel respondía 500 durante toda la actualización**, porque el despachador (root) dejaba el pedido en
  curso solo legible por root.
- **`install.sh` publicado no traía la llave pública:** el empaquetado ahora la incrusta y la prueba lo comprueba.
- **Leer el `.env`:**
  - `generate: null` inventaba claves VAPID falsas;
  - en bash 5.2, `&amp;` dentro de `${var//…}` escapaba mal la contraseña de ClickHouse.
- **Permisos de las carpetas de copias:** con 0700, la instancia temporal de MariaDB y ClickHouse no podían
  entrar a su subcarpeta; ahora la carpeta padre es 0711.
- **Al importar el historial de un sitio sin WooCommerce,** el plugin contaba las categorías de producto y
  lanzaba un aviso (`WP_Error`).
- **Del sistema operativo y los paquetes:**
  - `/etc/os-release` pisaba la variable `VERSION` del instalador;
  - el repositorio oficial de Redis instala Redis 8 por defecto y no trae `redis-server@.service`;
  - Caddy 2.11 rechaza `format` repetido dentro de `log`.

### Pruebas al cierre
- **Hub:** 1.033 de 1.033 en la suite completa, más las del arreglo del panel (8 de 8 en `tests/Feature/Updates`).
  Larastan sin errores, Pint limpio; SPA con 184 de Vitest y `tsc` limpio.
- **Scripts:**
  - actualizador («deployer falso», 220 comprobaciones);
  - copias (72);
  - empaquetado (firma, sha256 y alteraciones);
  - shellcheck limpio en todos.
- **Plugin:** 15 unitarias y 85 de WordPress. **Tracker:** 63 de Vitest y 21 de Playwright.
- **Recorrido:** `e2e-update.sh` en verde.

### Pendiente
- **Producción real:**
  - crear el par de llaves (`infra/release/keygen.sh`) y el secreto `GROWTH_RELEASE_SIGNING_KEY`;
  - publicar el primer release;
  - configurar `rclone`, `/etc/growth/backup.conf` y los heartbeats y monitores de Better Stack;
  - probar el TLS bajo demanda con dominios reales.
- **Python del ML:** `uv sync` usa el Python 3.12 de Ubuntu. Si una versión pide otro, el servidor necesita salida
  a GitHub para que uv lo baje.
- **Sin probar:** `setup-local.sh` en macOS.
- **Medición pendiente:** la prueba de carga con k6 sigue pendiente; ahora se puede correr contra un servidor
  instalado con `install.sh`.

## 2026-11-18 — Cierre de la Fase 5 (Servicios, agencia y escala)

### Criterio «Listo cuando» (medido con `infra/e2e/fase5.sh`)

El recorrido usa un hub aislado (`igr_e2e5`) con la agencia «Imagina La Web» y tres clientes. La «Clínica Dental Sonrisa» es un **WordPress corporativo sin WooCommerce**, con Contact Form 7 y el plugin conectado. «Pinturas La 14» es una tienda demo con 300 clientes y 1.500 pedidos. «Café Sierra» no tiene sitio.

Meta (Conversions API y WhatsApp Cloud API) y Google (OAuth y Data Manager) son **simuladores locales** (`infra/e2e/mock-meta.php`): se prueba lo que hace el hub, no la respuesta de las plataformas reales. No hay clave de Anthropic: el resumen del reporte salió con la plantilla sin IA.

| Criterio | Resultado |
|---|---|
| Un sitio corporativo sin WooCommerce usa el producto completo para leads | **Sí.** Ver el detalle debajo de la tabla. |
| La agencia ve a todos sus clientes en un tablero | **Sí.** El panel `/w/…/agencia` muestra los tres clientes: la clínica, conectada, con **$3.500.000 generados** («Lo que más aportó: Leads ganados»); la tienda, conectada; y el café, «Sin sitio». Desde ahí la agencia generó y descargó el **reporte mensual en PDF**: una página de 29 KB titulada «Reporte mensual de Clínica Dental Sonrisa · noviembre de 2026», con «$3.500.000». El dueño lo recibió por correo con el PDF adjunto y el asunto «…: $3.500.000 generados». |

Lo que hizo la clínica, paso a paso:
1. **El lead llega con su fuente.** Una persona llega desde un anuncio de Google (con `gclid`), mira «Servicios» y deja sus datos en el formulario. Nace un lead de **50 puntos**: formulario +30, página de servicios +10 y correo corporativo +10. Queda «tibio», con su fuente (Google Ads con el gclid), asignado a la vendedora y avisado a ella por **WhatsApp** con la plantilla de utilidad aprobada.
2. **La cita queda en el mismo lead.** La persona agenda su valoración con el botón `[igr_booking]` en la página de reservas del hub; el enlace llevó su visitante y el anuncio.
3. **Recordatorio y confirmación.** El **recordatorio de 2 h** salió por correo, y la respuesta «Confirmo» por WhatsApp **confirmó la cita**.
4. **Cotizador.** Otra persona usó el **cotizador** `[igr_quote]` en el celular: vio «$4.600.000 – $5.640.000 COP» y quedó como lead del cotizador con su cotización.
5. **Lead ganado y conversión offline.** El dueño marcó el primer lead como **ganado por $3.500.000** desde el panel. La conversión offline llegó a Meta (`action_source = system_generated`, con su valor) y a Google Ads (Data Manager, autorizada, con el valor y el **gclid** del anuncio).

### Qué se entregó
- **Leads (M18):**
  - Captura desde cualquier formulario y desde WhatsApp con intención de compra, con un lead abierto por contacto.
  - Puntaje por reglas, con IA opcional (`lead_qualifier`) y decaimiento semanal.
  - Aviso al vendedor y reparto por turnos o por servicio.
  - Kanban con arrastrar, lista, ficha con notas cifradas, KPI y embudo editable.
  - Conversión offline del lead ganado a Meta y Google Ads (una vez por lead, con consentimiento).
  - Detectores de hallazgos 21 a 23, condición «lead» en segmentos y tarjeta en el perfil.
- **Agenda y cotizador (M18):**
  - Página pública de reservas que funciona sin JavaScript, con días, horarios cada 15 min, festivos de Colombia, reprogramar, cancelar y `.ics`.
  - Recordatorios a 24 h y 2 h, confirmación o reprogramación por WhatsApp e inasistencias.
  - Google Calendar (sin probar con una cuenta real).
  - Cotizador por área, por opciones o por fórmula, con rango, mínimo y redondeo; el precio se muestra a cambio del contacto.
  - Shortcodes, bloques y widgets de Elementor también en sitios sin WooCommerce.
  - Condición «Citas» en segmentos.
- **API pública, webhooks y MCP (M19):**
  - `/api/v1` con 21 operaciones, tokens por alcance, límite por minuto del plan, idempotencia y documentación OpenAPI en `/api/docs`.
  - Webhooks firmados con reintentos y pausa automática.
  - Servidor MCP con 7 herramientas; las que escriben solo dejan borradores.
  - Presets del ecosistema Imagina; Imagina Turbo envía métricas web (`page.vitals`).
  - Pantalla «API e integraciones».
- **Planes y facturación (M21):**
  - Planes por pedidos o contactos activos, módulos por plan (Pennant) y uso medido cada 5 min (incluidas las peticiones a la API y MCP).
  - Avisos al 80 % y al 100 %, sin cortes bruscos.
  - Facturas manuales con PDF y enlace de pago.
  - Prueba, gracia de 7 días y suspensión, que frena solo el marketing, las importaciones y la IA.
  - Aviso en todo el panel y panel interno de la plataforma.
- **Agencia y marca blanca (M22):**
  - Tablero de clientes: dinero generado, salud, hallazgos críticos, uso y facturación.
  - Biblioteca de plantillas que se copian entre clientes sin datos personales.
  - Reporte mensual en PDF con marca: se envía el día 1 a las 08:00 locales y lo resume la IA validada o la plantilla.
  - Marca blanca con logo, color y remitente verificado.
  - Dominios propios verificados por DNS para el panel, los enlaces y la página de reservas.
  - Sesiones de soporte auditadas.
- **Bandeja:** Instagram y Messenger junto a WhatsApp.
- **Flujos de la biblioteca:**
  - 18 «Lead de servicios» (respuesta y 3 toques).
  - 19 «Recordatorio de cita», que lleva a la Agenda.
  - 20 «Renovación», con `contacts.renewal_at`, editable en el perfil, la importación y la API.
- **Contrato v5:** `lead.*`, `appointment.*`, `quote.requested` (solo del hub) y `page.vitals`.

### Errores que encontró la integración y el recorrido (corregidos con prueba)
- **Un negocio de servicios mostraba $0 generados:** `MoneyGenerated` solo contaba pedidos. Ahora cada lead ganado es una venta y suma al módulo que lo captó: formulario o WhatsApp, reservas o cotizador.
- **«2 a 8 millones» quedaba como $28:** el presupuesto ahora entiende rangos (toma el punto medio), «mil», «millones», «k» y «M».
- **El puntaje no veía la visita a precios justo antes del formulario.** La visita llegó anónima, y el enlace con el contacto (`identity_map`) llega a ClickHouse en el siguiente lote. Se corrigió de dos formas: los visitantes del contacto salen de MariaDB, y la lectura se repite a los 90 s (`RescoreLeadHistory`).
- **La cita perdía el anuncio que la trajo:** la página de reservas vive en el hub. El tracker ahora agrega al botón de reservas el visitante y el último contacto (UTM y click IDs). El tracker pesa 5.903 B gzip de 6.144.
- **El connector lanzaba un aviso en sitios sin WooCommerce:** al importar el historial contaba las categorías de producto (`WP_Error`).
- **El reporte de un sitio corporativo hablaba de «pedidos» y «ventas de la tienda»** y mostraba «Ventas $0». Ahora tiene un modo «servicios»: negocios cerrados, ventas con los leads ganados y sin las predicciones de recompra. También se corrigió el plural «1 pedidos».
- **La importación con la cuenta suspendida respondía 423:** Laravel usa ese código para «confirma tu contraseña», así que la SPA pedía la contraseña. Ahora responde 402 `billing_suspended` con su mensaje.
- **El panel fallaba (500) con un usuario sin `is_platform_admin` cargado** (`MissingAttributeException`).
- **El enlace del panel de agencia mostraba la clave `agency.modules.leads`** porque la compilación de la SPA era vieja. Se recompiló y la etiqueta neutra dice «de las ventas».
- **Pruebas:**
  - La de reintentos de webhooks fallaba a veces (59 frente a 60 s).
  - Una prueba de hallazgos todavía esperaba apagados los detectores 21 a 23.

### Pruebas al cierre
- **Hub:**
  - 1.007 pruebas Pest en la suite completa, más las que se agregaron después: la segunda pasada del puntaje y el reporte en modo servicios. Las carpetas afectadas pasaron.
  - Larastan nivel 8 sin errores en todo el hub; Pint limpio.
  - SPA: 173 pruebas de Vitest, `tsc` y ESLint limpios.
- **Connector:** 15 unitarias y 85 de WordPress (incluye `ServiceSlotsTest`); PHPCS 0 errores y 22 advertencias, las mismas de antes.
- **Tracker:** 63 de Vitest y 21 de Playwright (incluye el cotizador). Tamaños dentro del límite; `igr-w-quote.js` pesa 3.460 B de 4.096.
- **Recorrido:** `infra/e2e/fase5.sh` en verde en dos corridas limpias seguidas. Al final, el propio recorrido corre 207 pruebas de los módulos de la Fase 5 (Leads, Bookings, Quotes, Agency, Billing, Api, Webhooks y Mcp) en una base aislada.

### Cambios a la especificación (con su motivo, en el mismo commit que el código)
- **`page.vitals` entra en el contrato v5 y no abre una v6:** se agregó durante la Fase 5, antes de cerrarla, y ningún sitio envía todavía la v5.
- **Dinero generado en servicios:** cuentan los leads ganados (M22); sin eso, un sitio corporativo nunca muestra lo que generó (principio 7).
- **Flujo 19 como tarjeta de la Agenda:** el programador de la Agenda ya envía los recordatorios; un flujo encima los mandaría dos veces.
- **Flujo 18 como marketing:** por defecto, según cumplimiento.md §4.1, porque el caso es ambiguo.
- **Clarity recibe el `anonymous_id`, no el `contact_id`:** el navegador no conoce al contacto (M19).
- **Documentación de la API en `/api/docs`:** la SPA ocupa `/docs/*`.
- **Importación suspendida responde 402, no 423.**

### Pendiente y desviaciones conocidas
- **[verificar] con el modelo real** (`IGR_ANTHROPIC_API_KEY`): la calificación de leads con IA, el resumen del reporte mensual y lo que ya estaba pendiente de la Fase 4 (`igr:agent:eval`).
- **[verificar] con cuentas reales:**
  - El nombre del evento en Meta para el lead ganado (`Purchase` / `system_generated`) y los campos de Data Manager para `lead_won`.
  - Google Calendar.
  - La aprobación en Meta de las plantillas `wa_lead_reply`, `wa_renewal_reminder` y `team_alert` de cada cliente.
  - Las cuentas de Instagram y Messenger.
- **MCP:**
  - Solo con un token pegado; falta OAuth.
  - [verificar] los pasos exactos para agregarlo como conector en Claude.
  - [verificar] los nombres y URL de cada producto del ecosistema Imagina.
- **Dominios propios:** el TLS bajo demanda y el CNAME (`domains.growth.imagina.cloud`) no se probaron en producción.
- **Facturación:** el enlace de pago es manual (sin pasarela); los precios de los planes son de referencia [verificar con el equipo comercial].
- **Pendientes de prueba o de funcionamiento:**
  - Los widgets de Elementor no se probaron en un Elementor real.
  - La condición «Cita próxima» de los segmentos se actualiza con el recálculo programado, no en el momento.
  - Falta revisar si las tablas de la Fase 4 guardan datos de contacto que el borrado no cubre.
- **Heredados:** la imagen Docker del servicio `ml` y los recorridos manuales grabados de las fases 4 y 5.

## 2026-11-18 — Cierre de la Fase 4 (Inteligencia avanzada e IA)

### Criterio «Listo cuando» (medido con `infra/e2e/fase4.sh`)

El recorrido usa un hub aislado (`igr_e2e4`) con la tienda demo sembrada (3.660 clientes y 10.000 pedidos), el servicio de predicciones (`hub/ml`), la tienda WooCommerce real conectada y una API de WhatsApp simulada. **No hay clave de Anthropic en este entorno:** el hub habló con un **simulador de la API de Anthropic** con guion (`infra/e2e/mock-anthropic.php`), por el mismo SDK y la misma `AiGateway` que en producción. Eso prueba los controles del hub y el camino de herramientas; la prueba con el modelo real (`php artisan igr:agent:eval`) queda pendiente de `IGR_ANTHROPIC_API_KEY`.

| Criterio | Resultado |
|---|---|
| Las predicciones se validan contra un periodo de reserva y la app muestra su precisión | **Sí.** Reserva de 90 días. CLV (BG/NBD × Gamma-Gamma): 634 compras predichas frente a 729 reales (−13,0 %), correlación por cliente 0,65, ingreso −11,5 % → «Alta». Abandono: AUC 0,67 → «Media». Próxima compra: el 13,8 % compró a 15 días o menos de la fecha esperada (error mediano 65 días) → «Baja». La página `inteligencia/predicciones` muestra cada modelo con «qué tan confiable es», el gráfico predicho contra real por decil y la distribución de P(activo). Propensión: «datos insuficientes» (33.689 sesiones al mes de 50.000) |
| Ninguna cifra de un texto de IA falta en los hechos (prueba automática) | **Sí.** `NumberValidator` y `NarrativeWriter` en el analista, «Pregúntale», los resúmenes, el copiloto y el contenido, y `ReplyGuard` en el agente. 54 pruebas automáticas (Ai, Intelligence, Agent, Copilot). En el recorrido, el analista que citó los hechos quedó con `source = ai`; con una cifra inventada ($987.654.321) se regeneró una vez y terminó en la plantilla sin IA, sin que la cifra llegara al informe. «Pregúntale» respondió «vendiste $215.286.200», validado contra el resultado de la consulta |
| El agente resuelve preguntas de catálogo sin inventar precios ni stock | **Sí, con el modelo simulado.** WooCommerce tenía «Pintura para Cielos Blanca Galón» a $58.900 con 163 unidades; el agente llamó `search_products` y `get_product` (comando firmado `product.lookup`) y envió «…cuesta $58.900 y tenemos 163 unidades disponibles». Con un precio inventado ($71.900) no se envió nada: el turno pasó a una persona con `unverified_numbers`. Una pregunta fuera del negocio no usó herramientas y recibió la negativa acotada. «Quiero comprarlo» armó el carrito restaurable, y la bandeja creó el enlace de pago de WooCommerce con el producto |

### Qué se entregó
- **Base:** `AiGateway` sobre el SDK oficial de Anthropic para PHP (Sonnet 5.5 para análisis, copiloto y agente; Haiku 5.5 para clasificar y resumir), caché del system, salidas estructuradas, herramientas estrictas, respaldo del servidor ante rechazos, costo en `ai_usage` y tope mensual; `NumberValidator`, `NarrativeWriter` y `PiiScrubber`; contratos de la bandeja (`ConversationAgent`, `InboundClassifier`, `InboundMessageReceived`); permisos `useInbox`, `manageInbox` y `useAi`.
- **Predicciones (M17):** servicio Python `hub/ml` (FastAPI) con BG/NBD, MBG/NBD y Gamma-Gamma en numpy y scipy, validación con reserva; próxima compra por mediana de categoría, P(activo), compras esperadas, CLV a 12 meses, bandas de abandono, afinidad por categoría y marca, siguiente producto, rango de precio, mejor hora dentro de la ventana legal y puntaje de interacción.
- **Hallazgos:** los 20 detectores de `inteligencia.md` §5.1 (21 a 23 de servicios, en la Fase 5), impacto con la fórmula y supuestos editables, vigencia, regla de descarte y «Hacer ahora» en un clic.
- **Analista y datos:** analista de los lunes con 3 acciones; «Pregúntale a tus datos» con una sola herramienta sobre la capa semántica; resúmenes de perfil y de reseñas; clasificador de entrantes y moderador de reseñas con IA.
- **Copiloto:** propuestas de campaña y de flujo validadas (segmento, horario legal, plantillas aprobadas, costo y cumplimiento) que solo crean borradores; redactor de asuntos, variantes y textos de WhatsApp con el tono de la marca.
- **Bandeja (M11):** WhatsApp multiagente con asignación, ventana de 24 h, notas, respuestas rápidas, venta desde el chat (producto en vivo, carrito, enlace de pago, cupón), métricas, PWA con notificaciones push (VAPID) y tiempo real con Laravel Reverb.
- **Agente de ventas:** herramientas con precio y stock siempre en vivo, cifras validadas en código contra lo que devolvieron, modos asistido y automático, traspaso, registro y revisión de cada turno.
- **Connector:** comando `order.create_payment_link`.

### Errores que encontró la integración y el recorrido (corregidos con prueba)
- **El agente bloqueaba preguntas nuevas tras una «BAJA» anterior:** las reglas miraban los entrantes unidos del historial. Además dependía de si caían en el mismo segundo, por eso la prueba fallaba a veces.
- **El validador leía las URL como cifras:** un enlace de carrito o de pago contaba como un número sin respaldo.
- **El abandono con BG/NBD simple daba AUC 0,21:** trata a quien compró una vez como activo. El servicio ajusta también MBG/NBD y se queda con el que mejor predice la reserva.
- **Detector 9 (primera compra estancada):** la consulta de la mediana fallaba con datos reales.
- **Los segmentos confirmaban transacciones abiertas:** `DROP TABLE` en MariaDB hacía commit; ahora es `DROP TEMPORARY TABLE`.
- **La cola `ai` no tenía trabajador en Horizon:** el clasificador, el agente y los resúmenes nunca habrían corrido.
- **El rol agente no veía ningún contacto:** ahora ve los de sus conversaciones asignadas.
- **Faltaba el comando del enlace de pago en el connector.**
- **Las pruebas de contrato fallaban en una base nueva** y dejaban filas entre pruebas.

### Pruebas al cierre
- **Hub:** 767 pruebas Pest (Larastan nivel 8 sin errores, Pint limpio) y 141 de Vitest en la SPA.
- **Servicio `hub/ml`:** 8 pruebas de pytest.
- **Connector:** 15 unitarias y 80 de WordPress; PHPCS 0 errores y 22 advertencias.
- **Tracker:** sin cambios desde la Fase 3 (56 de Vitest y 19 de Playwright).
- **Recorrido:** `infra/e2e/fase4.sh` en verde en dos corridas limpias.

### Cambios a la especificación (con su motivo, en el mismo commit que el código)
- **SDK oficial de Anthropic para PHP en lugar de Laravel AI SDK:** es el que documenta Anthropic y trae salidas estructuradas, herramientas estrictas, caché de prompts y respaldo ante rechazos. La clave es propia del hub (`IGR_ANTHROPIC_API_KEY`) y nunca se leen las `ANTHROPIC_*` del sistema.
- **Predicciones en numpy y scipy, no `lifetimes`:** se eligió el modelo (BG/NBD o MBG/NBD) que mejor predice la reserva.
- **Bandeja:** la intención se calcula por palabras al llegar y la de IA va en cola, para no llamar al modelo dentro del webhook de Meta. Una baja detectada por IA solo revoca con confianza de 0,8 o más; por debajo marca la conversación para revisión humana.
- **«Agregar al tablero»** fija la consulta en la página de Inteligencia, porque los tableros de analítica son fijos.
- **El informe semanal sale por correo:** por WhatsApp necesita una plantilla aprobada y el teléfono del dueño.

### Pendiente y desviaciones conocidas
- **[verificar] con el modelo real:** `php artisan igr:agent:eval --workspace=ws_…` (10 preguntas sobre la tienda conectada) y el resto de las funciones de IA, cuando se configure `IGR_ANTHROPIC_API_KEY`.
- **Predicciones:**
  - La imagen Docker del servicio `ml` no se construyó aquí; corrió con uvicorn.
  - Las bandas de abandono cayeron a los cortes fijos (0,3 y 0,7) con los datos de demo.
  - La propensión no se construyó: está detrás de su umbral.
  - El parámetro `a` de MBG/NBD quedó en su mínimo. [verificar] con datos reales.
- **Hallazgos:**
  - El 11 compara solo el valor a 180 días (falta el gasto en anuncios por canal).
  - El 7 necesita los Web Vitals de Imagina Turbo.
  - El 13 no detecta la variante «ventas creciendo rápido».
  - El 16, [verificar] el código de bloqueo de WhatsApp.
- **Bandeja:**
  - Los adjuntos guardan solo los metadatos (falta descargarlos de Meta).
  - La barra lateral de la app no se adapta al celular (la bandeja ocupa toda la pantalla).
  - No hay satisfacción por conversación.
- **Copiloto:**
  - Las campañas aún no adjuntan un cupón por persona.
  - `WhatsAppTemplateRules` está marcado [verificar] frente a las reglas vigentes de Meta.
- **Manual:**
  - El recorrido grabado de la fase.
  - Widgets de Elementor sin probar (desde la Fase 3).

## 2026-11-18 — Cierre de la Fase 3 (Captación y fidelización)

### Criterio «Listo cuando» (medido con `infra/e2e/fase3.sh`)

El recorrido usa la tienda demo (WooCommerce) conectada a un hub aislado (`igr_e2e3`), con correo por SMTP hacia un buzón local. La comerciante deja activos un popup con WhatsApp y cupón del 10%, la bienvenida de referidos, el programa de puntos, el de referidos y las solicitudes de reseña por correo con publicación automática en WooCommerce.

| Criterio | Resultado |
|---|---|
| Un visitante anónimo deja su WhatsApp en un popup | **Sí.** El popup sale del `widgets.json` real, con la casilla de WhatsApp sin marcar. El consentimiento queda concedido con origen `widget:wg_…` y la versión vigente del texto legal |
| Recibe el cupón | **Sí.** `BIENVENIDA-…` en pantalla, creado en la tienda con `coupon.create`, uno por persona y widget |
| Compra | **Sí.** Paga con ese cupón; el contacto del popup y el del checkout se fusionan. El pedido queda con `attributed_widget_id` (paso 4 de la atribución) |
| Gana puntos | **Sí.** Registro 100, pedido 350 (pendientes al pagar, confirmados al completar), reseña 50, reseña con foto 100 y referido 500: saldo 1.100, igual al libro, sin nada pendiente |
| Deja una reseña con foto | **Sí.** La solicitud llega por correo; desde el celular deja 5 estrellas y un JPEG con GPS en el EXIF. La foto servida ya no tiene EXIF ni GPS. Se publica sola y llega a WooCommerce como reseña nativa «verificada» con la foto (`review.publish`). La página de gracias ofrece Google a todos, sin filtrar por nota |
| Refiere a un amigo | **Sí.** «Mis recompensas» muestra sus puntos y su enlace `?ref=MART…`. Luis lo abre, la bienvenida de referidos le da `AMIGO-…` y compra con él. Su pedido queda con `attributed_referral_id` y canal `affiliates`; el referido termina `rewarded` |
| Todo queda en su perfil y atribuido | **Sí.** La línea de tiempo de Marta tiene `widget.submitted`, `order.paid`, `order.completed`, los `points.earned`, `review.requested`, `review.collected` y `referral.converted`. 0 rechazos en la ingesta y 0 eventos muertos en el outbox |

**La revisión manual del referido:** al probar desde la misma máquina, el referido de Luis sale con la señal antifraude `same_ip` y pasa a revisión. La comerciante lo aprueba en el script y el job de calificación lo premia. Es el comportamiento esperado.

### Qué se entregó
- **Base de la fase:**
  - Contrato v4: `context.referral_code` y `touch.referral_code`, y los esquemas de `points.*`, `tier.changed`, `wallet.*`, `referral.shared`/`converted`, `review.requested`/`collected`, `nps.answered` y `product_watch.*`.
  - 17 tablas nuevas y los endpoints públicos de §8.3.
  - Interfaces compartidas: `CaptureContact`, `LoyaltyLedger`, `ReferralRewards` y `RecommendationService`.
- **M12 Captación:**
  - Popup (centro, esquina, pantalla completa), formulario incrustado, barra, teaser, dos pasos, ruleta (el premio se sortea en el hub), quiz con ramas y recomendaciones, widget de WhatsApp y bienvenida de referidos.
  - Variantes A/B con ganadora automática, segmentación, programación y vista previa.
  - Doble opt-in por correo.
  - Estadísticas por variante con ingresos atribuidos.
- **M13 Fidelización:**
  - Libro de puntos solo de inserción e idempotente: pendientes, confirmados tras la devolución, revertidos en reembolsos y con vencimiento FIFO.
  - Niveles VIP con `customer.set_tier`.
  - Recompensas canjeables por cupón o saldo.
  - Saldo a favor con «Usar saldo» firmado y débito pendiente.
  - `/me`, `/points` y `/program`; los nodos «dar puntos» y «dar saldo» en los flujos.
- **M14 Referidos y afiliados:**
  - Códigos legibles y regalo único para el amigo.
  - Señales de fraude con revisión manual.
  - Recompensa al referidor tras la calificación.
  - Afiliados con comisiones y pagos en CSV, y el portal `/creadores/{token}` con enlace mágico.
  - Paso 3 de la atribución.
- **M15 Reseñas y NPS:**
  - Solicitudes programadas.
  - Formulario público con fotos y video.
  - Moderación con heurística y un punto para conectar la IA de la Fase 4.
  - Publicación, respuesta y retiro en WooCommerce.
  - Importación nativa, de CSV y de Judge.me.
  - Widgets públicos.
  - NPS y CSAT con alertas de detractores.
- **M16 Comercio en el sitio y audiencias (M06):**
  - Seis tipos de recomendación con reglas de asociación nocturnas.
  - «Avísame» por stock y precio, con tope.
  - Banners por audiencia.
  - Audiencias hacia Meta, Google y TikTok cada 12 h, con altas y bajas por hash.
- **En la tienda:**
  - Tracker: runtime de widgets y un paquete por tipo, cada uno con su límite de tamaño; captura de `?ref=`.
  - Plugin: bloques, shortcodes, widgets de Elementor, «Mis recompensas», «Usar saldo» y los comandos de reseñas y niveles.
- **Integración:**
  - Supresión y exportación con los datos de las Fases 2 y 3, y salida de las audiencias.
  - Línea de tiempo del perfil con los eventos nuevos.
  - `contacto.nivel` y `enlaces.referido` en las plantillas.
  - Consentimiento `ads` desde el banner.
  - Segmentos por mensajería (pendiente desde la Fase 2) y por fidelización.
  - Biblioteca de flujos: 14 de 20.
  - Conciliación del contrato con una prueba que fija cada respuesta.

### Errores que encontró la integración y el recorrido (corregidos con prueba)
- **Enlaces de baja y preferencias rotos (Fase 2):** salían envueltos en `/c/`, por `LinkRewriter`.
- **`tienda.url` vacía (Fase 2):** salía vacía en todas las plantillas porque buscaba un estado de sitio que no existe.
- **Generador de la tienda demo no determinista:** según qué pruebas corrieran antes, una segunda corrida salía distinta; ahora usa su propio motor aleatorio.
- **13 diferencias entre la tienda y el hub:** las más graves eran estas.
  - Sin las banderas `onsite` en `/v1/s/config`, el plugin nunca mostraba puntos, «Mis recompensas» ni «Usar saldo».
  - En el formulario de dos pasos, decir que no al WhatsApp perdía el cupón del primer paso.
  - Las fechas de vencimiento salían un día antes en Colombia.
- **Texto para quien refiere:** decía «tu primera compra» en vez de «su».

### Pruebas al cierre
- **Hub:** 649 pruebas Pest (Larastan nivel 8 sin errores, Pint limpio) y 122 de Vitest en la SPA.
- **Tracker:** 56 de Vitest y 19 de Playwright contra la tienda. El tracker pesa 5.733 B gzip; los paquetes de widgets, de 958 a 6.137 B gzip, todos dentro de su límite. El quiz está a 7 B de su límite.
- **Connector:** 15 unitarias y 78 de WordPress. PHPCS: 0 errores y 22 advertencias.
- **Recorrido:** `infra/e2e/fase3.sh` en verde en una corrida limpia.

### Cambios a la especificación (con su motivo, en el mismo commit que el código)
- **api-connector.md §8.3, reescrito con las formas finales:**
  - Llaves de `design` y `content` de los widgets y `consents` arriba en `widgets.json`.
  - Respuesta del envío del widget; `/reco` con `fallback` y `/banner` con `id`.
  - `/watch` con `consent_version` y `available`; `/program`; `/me/redeem` con `wallet`.
  - `/reviews?scope=all`.
  - Confirmación del doble opt-in en `/p/{key}/confirmar/{token}`: `/confirmar/` lo tomaba la SPA.
- **§5 y §6:** `onsite` en `/v1/s/config`; `customer.set_tier` responde `422 role_not_allowed` con roles que dan más que `read`.
- **`?ref=`:**
  - Se deja en la URL: no es un dato personal y otros plugins de afiliados lo leen.
  - `context.referral_code` viaja aunque no haya consentimiento de analítica, porque identifica a quien refiere, no al visitante.
- **Puntos por referir:** se configuran solo en Referidos (M14), para que no haya dos valores que se contradigan.
- **Biblioteca de flujos:**
  - 12 (VIP), 15 (puntos por vencer, con el nuevo disparador por fecha `points_expiring_at`) y 16 (invitar a referir) son flujos.
  - 16 se dispara con los promotores del NPS; la «segunda compra» espera a que el disparador acepte varias condiciones.
  - 8 (pedir reseña), 14 (avisos de stock y precio) y 17 (NPS) los hace su módulo y la tarjeta lleva a su configuración, para no escribirle dos veces a nadie.
- **Consentimiento `ads`:** sale de la decisión del banner de cookies sobre marketing de un visitante identificado. Sin una fuente, el modo estricto dejaba las audiencias vacías.

### Decisiones pendientes del producto
- **[validar con el abogado]** Que aceptar las cookies de marketing valga como consentimiento para subir el correo con hash a audiencias, y la regla de «según el sitio» (todos menos quien revocó).
- **[validar con el abogado]** Que las solicitudes de reseña y los avisos de stock sean de marketing por defecto; se pueden reclasificar con justificación y queda auditado.
- **[validar con el abogado]** Los textos de consentimiento por defecto.

### Pendiente y desviaciones conocidas
- **[verificar]** con cuentas reales:
  - Las API de audiencias de Meta, Google (Ads API + Data Manager) y TikTok.
  - El encabezado `CF-IPCountry` de Cloudflare para el país en `visitor.json`.
  - El enlace de reseñas de Google Business.
  - Las fotos HEIC del iPhone.
- **Tienda:**
  - Widgets de Elementor sin probar (Elementor no está en la tienda de prueba).
  - «Avísame» por variación.
  - Los puntos en el carrito de bloques.
  - Clics de recomendaciones sin medir.
  - `_igr_ct` puede no actualizarse con la Store API.
  - El cuadro de saldo aparece cuando responde `/me` (un pequeño salto en el checkout).
  - El beneficio «envío gratis» del nivel no se aplica en la tienda.
- **Hub:**
  - Vencimiento del saldo.
  - Al fusionar contactos, el libro de puntos se mueve en el job nocturno.
  - «Llegó referido» en segmentos se actualiza con el siguiente evento o el recálculo nocturno.
  - Las reseñas nativas de WooCommerce no traen texto (haría falta un comando `review.get`).
  - Sin consentimiento de analítica, el segundo paso de un formulario de dos pasos se une al primero solo por la identidad normal.
- **Manual:**
  - El recorrido grabado de la fase.
  - Revisar en el navegador las pantallas nuevas de segmentos (tienen pruebas de componentes).

## 2026-11-18 — Cierre de la Fase 2 (Mensajería y automatizaciones)

### Criterio «Listo cuando» (medido con `infra/e2e/fase2.sh`)

El recorrido usa la tienda demo (WooCommerce 11.3) conectada a un hub aislado, con correo por SMTP hacia un buzón local y el flujo «Carrito abandonado» de la biblioteca activado en un clic. Las esperas del flujo (1 h, 4 h y 24 h) se publicaron en 1 minuto desde el constructor, como lo haría la comerciante, para correr la prueba en tiempo real.

Marta compra por primera vez y acepta recibir correos. Vuelve con las mismas cookies, arma un carrito, entra al checkout y se va. El job de carritos lo marca abandonado; `--at` simula los 45 minutos. El flujo le envía el correo «Marta, dejaste algo en tu carrito». Ella lo abre en otro dispositivo, la tienda le restaura el carrito y paga con tarjeta.

| Criterio | Resultado |
|---|---|
| El flujo de carrito abandonado recupera pedidos en la tienda de prueba de punta a punta | **Sí.** El carrito quedó `recovered` con el pedido nuevo: `restored_from` une el carrito del enlace con el abandonado. El run salió por la meta (`goal_reached`), con un paso por nodo. El correo salió por SMTP con baja en un clic (RFC 8058) y se registró el clic del enlace rastreado. ClickHouse tiene `cart.abandoned`, `flow.entered`, `message.queued`, `message.sent`, `message.clicked` y `flow.exited` |
| Ningún mensaje de marketing sale un domingo, un festivo o fuera de horario en Colombia (con prueba automática) | **Sí.** Hay cuatro capas de prueba, detalladas debajo de la tabla |
| Cada pedido recuperado aparece atribuido | **Sí.** `attributed_message_id` apunta al correo y `attributed_flow_id` al flujo. El canal del último toque es `igr_email` |

Las cuatro capas de prueba de la ventana legal:
- **`SendPolicyColombiaTest`** (19 pruebas):
  - De lunes a viernes, de 7:00 a 18:59; desde las 19:00 se aplaza.
  - El sábado se aplaza a partir de las 15:00; a las 15:01, al lunes.
  - Domingos.
  - Festivos fijos, trasladados por la Ley Emiliani y de Pascua.
  - Cambio de día.
  - Frecuencia de 1 al día sumando canales, con dos reservas simultáneas.
  - Excepciones de festivos.
- **Ventana justo antes de enviar:** el job vuelve a mirar la ventana antes de llamar al proveedor.
- **Flujos y campañas:** en sus pruebas, un paso del domingo a las 10:00 sale el lunes a las 7:00 y un reintento nunca reenvía.
- **El recorrido:** domingo, 8 de diciembre y sábado a las 15:01 en Bogotá se aplazan.

**La ventana legal y la hora del recorrido:** el recorrido corrió a la 1:30 de Bogotá, con la ventana cerrada. Por eso la zona horaria de Marta se fijó en una donde estuviera abierta (`fase2-hub.php reloj`): con la de Bogotá, el correo habría salido a las 7:00.

### Qué se entregó
- **Base de la fase:**
  - Contrato v3: `order.cart_token`, `cart.updated.restored_from` y los esquemas de `cart.abandoned`, los 11 `message.*`, `whatsapp.inbound` y `flow.entered`/`flow.exited`.
  - 18 tablas nuevas.
  - **SendPolicy**: ventana legal por país, festivos con excepciones editables (`igr:holidays`), frecuencia atómica en Redis, consentimiento, supresión, registros de exclusión, cuenta del canal y ventana de 24 h de WhatsApp.
  - Interfaces compartidas: despachador, renderizador, emisor de cupones y enlace de carrito.
- **Carritos y cupones:**
  - Proyección de carritos y abandono con `cart.abandoned`, que trae los productos y el enlace de restauración firmado.
  - Recuperación o conversión al llegar el pedido.
  - Cupones del hub creados en el sitio y redimidos.
  - El `_igr` de un enlace vincula el navegador con el contacto, sin fusionar perfiles.
- **M07 Canales:**
  - Correo: SES, con el asistente DKIM/SPF/DMARC y el calentamiento, o SMTP.
  - WhatsApp: oficial (Embedded Signup o conexión manual), con plantillas creadas en Meta desde la app; o Zender, con el riesgo aceptado, topes fijos y sin marketing.
  - SMS por Twilio.
  - El despachador es idempotente y devuelve el cupo de frecuencia si el envío no llega al proveedor.
  - Webhooks con firma verificada.
  - Palabras de baja por WhatsApp; baja en un clic y centro de preferencias.
  - Supresión e importación del RNE y el REPEP.
  - Atribución por mensaje y la pestaña «Mensajes» del perfil.
- **M08 Plantillas:**
  - Liquid en sandbox, con revisiones: un correo de marketing sin enlace de baja no puede quedar listo.
  - Editor de correo GrapesJS + MJML con bloques dinámicos.
  - Biblioteca de 29 correos y 7 SMS por industria.
- **M09 Flujos:**
  - Disparadores por evento, segmento, fecha, umbral, webhook o a mano.
  - Nodos de espera, condición, A/B, canal, envío, cupón, etiqueta, atributo, aviso al equipo y webhook.
  - Metas, grupo de control, versionado, analítica por nodo con incremento y modo prueba.
  - Constructor con React Flow.
  - Biblioteca: 11 flujos funcionan hoy; 9 quedan marcados para su fase (3 o 5).
- **M10 Campañas:**
  - Asistente de 5 pasos con la revisión de cumplimiento y el costo estimado.
  - Envío por lotes idempotente.
  - A/B con ganadora automática, grupo de control e incremento.
  - Calendario comercial de Colombia, México y Venezuela.
- **Connector:**
  - Comandos `coupon.create`, `coupon.disable`, `order.add_note`, `product.lookup` y `order.status`.
  - Carrito restaurable (`?igr_cart=`) sin llamadas al hub.
  - `cart_token` en el pedido, con el contrato v3.
- **Consentimiento:** el modo de cookies se elige en el panel (decisión posterior al cierre de la Fase 1, ver abajo).

### Errores que encontró el recorrido (corregidos con prueba)
- **Carrito recuperado:** el enlace de recuperación arma un carrito nuevo en WooCommerce. Antes el pedido cerraba ese carrito nuevo como convertido y el abandonado nunca quedaba recuperado. Ahora `carts.restored_from_id` los une.
- **Atribución en el mismo segundo:** WooCommerce guarda la fecha del pedido sin milisegundos. La visita que llegó desde el correo en ese mismo segundo quedaba fuera, y el pedido caía al canal de una visita anterior. Ahora cuenta hasta el final de ese segundo.
- **Imagen rota en el correo:** un producto sin imagen dejaba `<img src="">`, porque `item.imagen != ""` se cumple con el valor nulo.
- **Integración entre forks:** funciones auxiliares de las pruebas con el mismo nombre (abortaban la suite completa) y la variable `campana` de las campañas en las plantillas.

### Pruebas al cierre
- **Hub:** 441 pruebas de Pest, Larastan nivel 8 sin errores y Pint.
- **SPA:** 90 pruebas de Vitest, TypeScript estricto, ESLint y build.
- **Tracker:** 22 pruebas de Vitest y 11 de Playwright. Pesa 5.629 B gzip.
- **Connector:** 15 pruebas unitarias y 67 con WordPress y WooCommerce reales. PHPCS sin errores.

### Cambios a la especificación (con su motivo, en el mismo commit que el código)
- `eventos.md` §4.6 y §8: contrato v3.
- `api-connector.md`:
  - §6: los comandos de la Fase 2, con sus errores.
  - §6.1: el enlace de carrito restaurable.
  - §5.1 y §7: el modo de consentimiento elegido en el panel.
- `modelo-de-datos.md` §12: las tablas de la Fase 2 y sus diferencias. `messages` y `flow_run_steps` quedan sin partición mensual por las claves ULID, y la purga la hace un job.
- `cumplimiento.md`:
  - §3.3: los tres modos de cookies.
  - §5 y §8: notas de implementación de WhatsApp y correo.
- `modulos.md`: M04 estado medido, y notas de implementación de M07, M08, M09 y M10.
- `hub/CLAUDE.md`:
  - §6: segmentos.
  - §7: flujos.
  - §8: contratos internos, enlaces rastreados y `_igr`.
  - §15: colas `flows`, `messages:*` y `redis-long`.

### Decisiones pendientes del producto
- **Frecuencia frente al carrito abandonado.** Con el tope colombiano de 1 mensaje comercial al día sumando canales (cumplimiento.md §4.2, **[validar]**), el segundo toque del flujo (correo a las 4 h) se aplaza al día siguiente: no se pierde, pero llega tarde. Hay que decidir si los flujos de recuperación tienen su propio tope o si cambia el valor por defecto.
- **Plantillas de WhatsApp y la biblioteca.** Meta debe aprobar cada plantilla, así que la biblioteca no trae plantillas de WhatsApp listas. Si la tienda tiene WhatsApp activo, la comerciante elige la plantilla aprobada en «Personalizar» antes de activar el flujo; sin WhatsApp, el flujo se activa solo con correo.
- **Carrito y checkout abandonados al mismo tiempo.** Los flujos 2 y 3 no se excluyen por defecto, así que una persona puede entrar a los dos.

### Pendiente y desviaciones conocidas
- **Por verificar con cuentas reales:**
  - Meta: la ruta `/marketing_messages`, la versión de la API, Embedded Signup, que una plantilla creada desde la app quede aprobada y los nombres de los niveles de límite.
  - SES: el conjunto de configuración y SNS, y que un correo pase la revisión de Gmail con DKIM alineado. Es el criterio de M07.
  - La detección de aperturas automáticas de Apple.
  - El formato de Zender y la firma de Twilio.
  - Las tarifas de WhatsApp: valores de la tabla de julio de 2025, de memoria.
  - Las fechas variables del calendario comercial.
- **WhatsApp de punta a punta:** el recorrido usó solo correo. El envío por WhatsApp está cubierto por pruebas con el proveedor simulado.
- **Los 20 flujos de M09:** 11 funcionan; los de fidelización, reseñas, NPS, referidos, avisos de stock, citas, leads y renovaciones llegan con sus módulos (Fases 3 y 5).
- **Otros pendientes:**
  - El driver de Infobip.
  - Avisarle a la dueña cuando baja la calidad del número; hoy solo se registra, y la política ya frena el marketing.
  - Subir la imagen o el video de encabezado de una plantilla de WhatsApp.
  - Las variaciones «cualquiera» en el carrito restaurado.
  - `order.status` con plugins de numeración secuencial.
- **Arrastrados de fases anteriores:** el recorrido manual grabado (lo graba el equipo), la prueba de carga con k6 y `caddy validate`.

## 2026-11-18 — Cierre de la Fase 1 (Conocer al cliente)

### Criterio «Listo cuando» (medido con `infra/e2e/fase1.sh`)

La tienda demo de la Fase 0 (WooCommerce 11.3, 10.000 pedidos en COP de 24 meses) se conecta a un hub aislado e importa su historial en 66 s. Después, en Chromium real, una visitante llega desde un anuncio de Facebook (`utm_source=facebook&utm_medium=paid_social&fbclid=…`), acepta el banner propio, mira un producto, busca, compra con tarjeta por la Store API (la del checkout de bloques) marcando WhatsApp y correo, y responde «¿cómo nos conociste?». Meta se simula: `fbevents.js` en el navegador y la Graph API en el servidor.

| Criterio | Resultado |
|---|---|
| El tablero responde en menos de 1,5 s con un año de datos | **Sí.** Los 12 tableros, pedidos por HTTP con la sesión y el 2FA de la dueña, sin caché y con 12 meses comparados con los 12 anteriores: máximo **649 ms** (Clientes), Inicio 121 ms. Con navegación (`igr:seed-demo --browsing`: 10.000 pedidos, 1,78 millones de eventos de navegación y 342.025 sesiones), el p95 más lento fue Canales con 592 a 820 ms |
| Meta muestra la deduplicación correcta entre píxel y servidor | **Sí, contra Meta simulado.** El `Purchase` del píxel y el de la Conversions API llevan el mismo `event_id` (el UUIDv5 de `order.paid`), con el mismo valor y la misma moneda, `fbc` del clic, `fbp`, y correo y teléfono en SHA-256. El píxel dispara una sola vez aunque se recargue la página, y cada `PageView` tiene su `page.viewed` con el mismo `event_id`. **Falta verlo en el Administrador de eventos con una cuenta real** (ver «Pendiente») |
| El informe semanal por correo llega al dueño | **Sí.** Se entregó por SMTP a la dueña: «Tu semana en Pinturas La 14: $22.267.950 en ventas». Queda registrado en `report_deliveries` y no se envía dos veces la misma semana |

El mismo recorrido comprueba 36 puntos más, todos en verde:
- Identidad: la cookie del navegador queda en el mismo contacto que el pedido.
- Atribución: el pedido queda en `paid_meta`.
- Perfil 360: la línea de tiempo tiene navegación, carrito, checkout, pedido, encuesta y consentimiento.
- Consentimiento: queda con su texto legal versionado y la huella de la IP.
- Datos personales: ningún evento guarda el correo en claro.
- Segmentos: la compradora entra a «Nuevos» con su `segment.entered`.
- RFM: queda calculado para 1.823 clientes.

El perfil abre en 869 ms con la SPA incluida.

### Qué se entregó
- **Contrato v2:**
  - `identify.token` firmado por el sitio.
  - Eventos que solo emite el hub: `survey.answered`, `segment.entered`/`exited`, `consent.granted`/`revoked` y `conversion.delivered`. Si llegan desde un sitio, se rechazan.
  - En el navegador, la identidad solo se acepta con un token firmado.
- **Tracker (5,6 KB gzip):**
  - Registra vistas, UTM y `_igr`, clics a WhatsApp con token, búsquedas e `identify`.
  - Funciona en modo estricto o informado según haya banner o no.
  - Carga aparte, solo cuando hacen falta, el paquete de píxeles (Meta, TikTok y Google con el `event_id` del servidor; la compra solo con `igrOrder.paid`), el banner y los widgets.
- **Connector:** contexto de página, tokens firmados, banner propio y adaptadores de banners, casillas de consentimiento en los checkouts clásico y de bloques, y el espacio para la encuesta.
- **Hub:**
  - Identidad con fusiones auditadas y reversibles por 30 días, perfil 360, importación CSV con declaración del origen del consentimiento, registro de consentimiento con evidencia, encuesta «¿cómo nos conociste?» y solicitudes de acceso y borrado con plazos legales.
  - Segmentación (M04): vista previa entre 137 ms y 1,45 s con 100.000 contactos y 5 millones de eventos, recálculo en tiempo real y nocturno, y biblioteca de 17 segmentos.
  - Analítica (M05): capa semántica y 12 tableros. También RFM, 5 detectores de hallazgos e informe semanal.
  - Medición (M06): Conversions API de Meta, Data Manager de Google y Events API de TikTok con reintentos, atribución con respaldo y gasto en anuncios.
- **SPA:** Analítica, Hallazgos, Contactos, Perfil 360, Importar, Segmentos (constructor visual), Medición, Consentimiento y Encuesta, con es-CO, es-MX y es-VE.

### Errores que encontró el recorrido (corregidos con prueba)
- El perfil de un contacto con RFM daba 500 porque `rfm_computed_at` no tenía cast a fecha.
- `survey.answered` se guardaba sin el `anonymous_id`, así que no salía en el perfil. Además, la respuesta que llega antes que el pedido solo se ligaba por un job que reintenta varios minutos después. Ahora se liga al proyectar el pedido.
- Los comandos `igr:*` leían `--workspace` cada uno a su manera. Con un ID que no reconocían, unos terminaban en 0 sin hacer nada y `install-library` aplicaba a todos los espacios de trabajo. Ahora `WorkspaceOption` es uno solo y falla con un mensaje claro.
- La segmentación usaba `potential_loyalists` y el cálculo RFM `potential_loyal`. Una prueba amarra ahora las claves.
- El perfil mezclaba zonas horarias: la actividad salía en hora de Bogotá y la cabecera en la del navegador. Toda la SPA usa ahora la zona del espacio de trabajo.
- Horizon no atendía las colas `analytics` ni `triggers`, y los jobs largos tenían un `retry_after` de 90 s, menor que su timeout. Ahora hay supervisores para esas colas y la conexión `redis-long`.

### Pruebas al cierre
- **Hub:** 297 pruebas de Pest, Larastan nivel 8 sin errores y Pint.
- **SPA:** 64 pruebas de Vitest, TypeScript estricto y ESLint.
- **Tracker:** 22 pruebas de Vitest, límite de tamaño y 9 pruebas de Playwright contra WooCommerce real y un hub simulado que valida cada evento contra los esquemas.
- **Connector:** 15 pruebas unitarias y 55 con WordPress, WooCommerce, Contact Form 7 y WPForms reales; PHPCS sin errores.
- **CI:** nuevos trabajos para el tracker y su prueba de navegador.

### Cambios a la especificación (con su motivo, en el mismo commit que el código)
- `eventos.md`: contrato v2 (sube `schema_version`), regla de identidad del navegador, esquemas de los eventos del hub y tabla de hash por plataforma.
- `api-connector.md`:
  - §5.1: textos de consentimiento y píxeles en la configuración.
  - §5.4: solicitudes de privacidad desde WordPress.
  - §7: datos de la página, tokens firmados, campos de `igrConfig`, `igrOrder.paid` y modo de consentimiento efectivo.
  - §8: widgets y la respuesta «otro» de la encuesta.
- `modelo-de-datos.md` §11: columnas de la Fase 1, detalle de `conversion_deliveries` e índices bloom.
- `inteligencia.md` §3: claves RFM y segmentos del sistema `rfm_<clave>`.
- `modulos.md` M04: estado medido.
- `hub/CLAUDE.md`:
  - Decisiones de implementación.
  - §6: segmentos (retardo de 15 s, primer cálculo sin eventos y vista previa con tabla temporal).
  - §9: respaldo de la atribución.
  - §15: colas y `redis-long`.

### Pendiente y desviaciones conocidas
- **Modo de consentimiento (decidido después del cierre):** se elige en el panel entre «Según el sitio» (por defecto), «Estricto» e «Informado». El plugin y el hub lo aplican con el mismo criterio (`cumplimiento.md` §3.3, `api-connector.md` §7).
- **Verificar con cuentas reales** (`[verificar]` en `eventos.md` §7 y `config/igr-measurement.php`):
  - La deduplicación en el Administrador de eventos de Meta.
  - Las versiones de las API.
  - Los campos de Data Manager de Google.
  - Los nombres de evento de TikTok.
- **Recorrido manual grabado** (CLAUDE.md §6): lo graba el equipo. El recorrido automático deja video del panel y capturas, pero no reemplaza esa grabación.
- **Lo que llega en otras fases:**
  - Que una entrada o salida de segmento dispare un flujo en menos de 2 minutos se mide en la Fase 2, cuando existan los flujos. Hoy el recálculo corre 15 s después del evento.
  - Las condiciones de mensajería, fidelización y servicios muestran la fase en que llegan.
- **Sin probar:**
  - Gravity Forms y Elementor Pro, que son de pago.
  - Safari con ITP en un navegador real.
  - El espacio de la página de gracias frente al CLS en temas reales.
  - La regla de Cloudflare para el ping en sitios con «cache everything».
- **Otros pendientes:**
  - Agregar o quitar a mano miembros sueltos de una lista fija.
  - El admin del plugin en React.
  - La prueba de carga con k6 (500 eventos/s, pendiente desde la Fase 0).
  - `caddy validate` sobre `infra/Caddyfile`.
- **Limitaciones conocidas:**
  - El filtro por dispositivo solo acota las métricas de tráfico, porque los pedidos no traen dispositivo.
  - La tienda demo no trae historial de navegación. Por eso, en el recorrido, «Sesiones» y «Conversión» de 12 meses salen en 0; el rendimiento con navegación se midió con `igr:seed-demo --browsing`.

## 2026-11-18 — Cierre de la Fase 0 (Cimientos)

### Criterio «Listo cuando» (medido con `infra/e2e/fase0.sh`)

Tienda WooCommerce 11.3 (HPOS) con `wp igr demo-store`: 2.000 clientes (1.296 registrados), 10.000 pedidos en COP de 24 meses, 511 reembolsos, 334 reseñas, pagos PSE, Nequi, contraentrega y transferencia. Hub con MariaDB 11.4, ClickHouse 25.8 y Redis 7.

| Criterio | Resultado |
|---|---|
| Se conecta en menos de 2 minutos | **1 s** (código manual y `wp igr connect`) |
| El backfill completo termina sin errores | **Sí**: el plugin envió todo en 47 s; el hub terminó de procesar 20.917 eventos a los 224 s, con **0 rechazos** |
| Conteos de pedidos y venta total coinciden al 100% | **Sí**, en los 7 estados: 10.000 / 10.000 pedidos y $1.916.485.780 / $1.916.485.780 |

Comprobaciones adicionales: 0 contactos con contadores desfasados, 0 pedidos sin contacto y ninguna propiedad con correos en ClickHouse. La misma prueba con `php artisan igr:seed-demo` (sin WordPress) también concilia al 100%.

### Qué se entregó
- **Contrato v1:** 43 esquemas JSON con ejemplos válidos e inválidos y el contrato en el cable `docs/api-connector.md` (firma, handshake, ingesta, configuración, conciliación, comandos e IDs deterministas).
- **Hub (Laravel 13):** tenancy con pruebas de aislamiento, M01 (cuentas, espacios de trabajo, roles, agencia, 2FA obligatoria, invitaciones, auditoría), M02 (conexión, rotación, desconexión, conciliación), ingesta `/v1/s` y `/v1/b` con Redis Streams, worker con dedupe, esquemas, canal (inteligencia §2), identidad determinista, proyecciones en MariaDB y ClickHouse sin PII, festivos de Colombia y México, `igr:seed-demo`, `/health` y el `ask` de TLS bajo demanda.
- **SPA:** acceso con 2FA, onboarding, `/connect` para el plugin, sitios con progreso y conciliación, Inicio, Contactos, Equipo, ajustes y auditoría.
- **Connector 0.1.0:** conexión, outbox con reintentos, recolectores de WooCommerce, catálogo con conciliación por hash, backfill con conciliación, comandos firmados, admin en PHP, WP-CLI y la tienda demo.
- **Infraestructura:** Docker Compose local y de producción, imagen FrankenPHP, Caddyfile, copias de seguridad con guía de restauración y CI (hub, SPA y connector).

### Pruebas al cierre
Hub: 151 de Pest (unidad, contrato, integración y tenancy) contra bases reales, Larastan nivel 8 y Pint sin errores. SPA: TypeScript estricto, ESLint y 16 de Vitest. Connector: 15 unitarias y 27 con WordPress + WooCommerce reales que validan cada evento contra los esquemas del hub; PHPCS sin errores.

### Cambios a la especificación (con su motivo, en el mismo commit que el código)
- `eventos.md`: `last_touch`, foto opcional del pedido en eventos de estado, `modified_at`, `catalog.category_deleted`, datos de cliente para la importación, `hash` en `catalog.category_upserted` y `items[].cost` como costo unitario. Se hicieron antes de publicar el contrato v1, por eso no sube `schema_version`.
- `modelo-de-datos.md`: `ch_id` numérico para ClickHouse, `site_connect_codes`, `workspace_invitations`, columnas de sitios y pedidos, y `email_hash`/`phone_hash` como HMAC por espacio de trabajo (un SHA-256 plano se revierte con diccionario).
- `hub/CLAUDE.md`: auditoría con tabla propia, Fortify, opis/json-schema, cliente propio de ClickHouse y el worker de ingesta fuera de Horizon, con transacción por lote.

### Pendiente y desviaciones conocidas
- **Recorrido manual grabado** (CLAUDE.md §6): lo debe grabar el equipo con el entorno local de `infra/README.md`. Hay capturas de la SPA de la prueba automática, pero no un video.
- La prueba de punta a punta usa WP-CLI contra WooCommerce real, no Playwright con `wp-env`; la matriz de temas, cachés y pasarelas de `connector/CLAUDE.md` §14 queda para antes de la primera versión pública.
- El admin del plugin es una página PHP; el admin en React (`admin-app/`) llega después.
- `infra/Caddyfile` no se validó con `caddy validate` (no hubo acceso al binario en este entorno).
- Ingesta medida en ~117 eventos/s por shard; la prueba de carga con k6 (500 eventos/s) queda para la Fase 1.
- Fusiones de contactos (auditadas y reversibles), tracker y todo lo de la Fase 1 siguen pendientes.

## 2026-11-17 — Especificación inicial
- Visión, arquitectura (plugin ligero + hub en Laravel + ClickHouse), hoja de ruta en 6 fases.
- Contrato de eventos v1, modelo de datos, 22 módulos, inteligencia (RFM adaptado a LATAM, predicciones, 23 detectores de hallazgos, IA), cumplimiento (Ley 1581, Ley 2300, LFPDPPP de México, políticas de WhatsApp), análisis de competencia.
