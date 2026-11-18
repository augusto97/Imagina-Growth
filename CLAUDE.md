# Imagina Growth — CLAUDE.md principal

> **Nombre provisional.** Para cambiarlo, busca y reemplaza `Imagina Growth`, `imagina-growth`, el prefijo `igr` y el dominio `growth.imagina.cloud`.
> Este archivo es el punto de entrada para Claude Code. Antes de tocar un módulo, lee el documento que le corresponde en `/docs` y el `CLAUDE.md` de la carpeta donde vas a trabajar.

---

## 1. Qué es

Imagina Growth es una plataforma SaaS para que **tiendas WooCommerce y sitios de servicios o corporativos en LATAM** (Colombia, México y Venezuela primero) consigan más clientes, les vendan más y los retengan.

Reúne en un solo producto lo que hoy exige 6 a 8 herramientas sueltas:

1. **Datos del cliente (CDP):** perfil 360 de cada comprador o lead, con todo lo que pasa en el sitio, la tienda y los mensajes.
2. **Inteligencia:** segmentos RFM, predicciones (próxima compra, riesgo de abandono, valor futuro), hallazgos automáticos y un analista con IA que dice qué hacer.
3. **Medición y anuncios:** conversiones enviadas desde el servidor a Meta, Google y TikTok, atribución y audiencias sincronizadas.
4. **Mensajería y automatizaciones:** WhatsApp oficial, correo y (opcional) SMS, con flujos y campañas que respetan consentimiento, horarios legales y límites de frecuencia.
5. **Captación en el sitio:** formularios, popups, quiz, widget de WhatsApp con atribución.
6. **Fidelización:** puntos, niveles VIP, saldo a favor, referidos, afiliados e influencers.
7. **Prueba social:** reseñas con foto, NPS y reseñas de Google.
8. **Conversaciones:** bandeja de WhatsApp multiagente con agente de ventas con IA.
9. **Capa de agencia:** Imagina La Web administra todos sus clientes desde un panel y les entrega un reporte mensual del dinero generado.

**Promesa al cliente final:** «Sabe quién te compra, por qué vuelve y qué hacer mañana para vender más. Y mira cada mes cuánto dinero generó la plataforma».

**Modelo de negocio:** suscripción mensual por espacio de trabajo, según pedidos o contactos al mes y los módulos activos. Cada cliente paga a Meta sus mensajes de WhatsApp desde su propia cuenta (ver `docs/cumplimiento.md` §5).

---

## 2. Piezas del sistema (monorepo)

```
imagina-growth/
├── CLAUDE.md                 ← este archivo
├── docs/                     ← especificación funcional (fuente de verdad)
│   ├── eventos.md            ← contrato de eventos y datos que se recogen
│   ├── api-connector.md      ← contrato en el cable entre el plugin y el hub (firma, handshake, ingesta, comandos)
│   ├── modelo-de-datos.md    ← tablas MariaDB y ClickHouse
│   ├── modulos.md            ← especificación por módulo, con fase y criterios
│   ├── inteligencia.md       ← métricas, RFM, predicciones, hallazgos, IA
│   ├── cumplimiento.md       ← Ley 1581, Ley 2300, México, políticas de WhatsApp
│   ├── competencia.md        ← análisis de competencia y decisiones de producto
│   └── CHANGELOG.md          ← historial de la especificación y cierre de fases
├── hub/                      ← app central: Laravel 13 + React (growth.imagina.cloud)
│   └── CLAUDE.md
├── connector/                ← plugin de WordPress «Imagina Growth Connector»
│   └── CLAUDE.md
├── tracker/                  ← script del navegador (TypeScript, se compila al plugin)
│   └── CLAUDE.md
└── infra/                    ← Docker Compose, despliegue, copias de seguridad
```

### Flujo de datos

```
 Navegador del visitante                    Sitio WordPress / WooCommerce
 ┌─────────────────────┐                   ┌──────────────────────────────┐
 │ tracker.js (≤6 KB)  │                   │ Imagina Growth Connector     │
 │ vistas, clics, UTM, │                   │ pedidos, carritos, clientes, │
 │ búsquedas, CTA      │                   │ formularios, catálogo,       │
 └─────────┬───────────┘                   │ reseñas, cambios del sitio   │
           │ beacon (público,              │ → outbox local → lote firmado│
           │ clave pública)                └──────────────┬───────────────┘
           ▼                                              │ HMAC, gzip, cada minuto
 ┌──────────────────────────────────────────────────────────────────────────┐
 │ HUB  growth.imagina.cloud                                                │
 │ ingest → cola → enriquecer → identidad → ClickHouse (eventos)            │
 │                                       → MariaDB (perfiles, pedidos…)     │
 │ segmentos · predicciones · hallazgos · flujos · campañas · bandeja · IA  │
 └───────┬─────────────────────┬──────────────────────┬─────────────────────┘
         │                     │                      │
   WhatsApp (Cloud API,   Correo (SES)         Meta CAPI · Google Data Manager
   Marketing Messages,    SMS opcional         · TikTok Events · audiencias
   Zender limitado)
         │
         └── comandos firmados al sitio: crear cupón, publicar reseña,
             restaurar carrito, consultar stock, etc.
```

---

## 3. Principios no negociables

1. **El sitio del cliente nunca se pone lento por nosotros.** El tracker pesa como máximo 6 KB gzip. No hay llamadas HTTP bloqueantes durante una petición de WordPress. Todo lo que va al hub sale por la cola (Action Scheduler) en lotes. El checkout gana como máximo 10 ms.
2. **Compatible con la caché de página.** El HTML del servidor nunca cambia según el visitante. Lo personalizado se carga con JS después de la página.
3. **Nada se pierde.** El plugin guarda los eventos en un outbox local con reintentos. El hub es idempotente por `event_id`.
4. **Aislamiento por espacio de trabajo.** Toda consulta (MariaDB, ClickHouse, Redis, archivos) va filtrada por `workspace_id`, con pruebas automáticas que lo verifican.
5. **Consentimiento primero.** Ningún mensaje de marketing sale sin consentimiento registrado para ese canal, fuera del horario legal del país del contacto o por encima del límite de frecuencia. Los mensajes que caen fuera del horario se aplazan, no se descartan.
6. **La IA no inventa números.** Recibe hechos ya calculados y solo redacta. Toda cifra de un texto de IA debe existir en los hechos que se le pasaron; si no, el texto se regenera o se descarta.
7. **Todo módulo muestra el dinero que generó.** La atribución es lo que hace que el cliente siga pagando.
8. **Hecho para LATAM.** Español neutro en la interfaz (`es_CO` por defecto, `es_MX`, `es_VE`), monedas COP, MXN y USD, pagos pendientes (PSE, Efecty, transferencia, contraentrega), departamentos y ciudades, festivos por país.
9. **Privacidad por diseño.** Los identificadores directos (correo, teléfono, nombre) se cifran en reposo con índice ciego para buscarlos. Nunca se guarda una IP completa. No se piden números de cédula ni NIT.

---

## 4. Stack

| Capa | Tecnología |
|---|---|
| Hub, backend | PHP 8.4, **Laravel 13**, MariaDB 11 (transaccional), **ClickHouse** (eventos y analítica), Redis 7 (colas, caché, límites), Laravel Horizon, Laravel Reverb (tiempo real), Laravel Sanctum, Laravel AI SDK (Anthropic), Laravel MCP |
| Hub, frontend | React 18+ con TypeScript, Vite, shadcn/ui + Tailwind (prefijo propio), TanStack Query/Table, Zustand, React Hook Form + Zod, Recharts, React Flow (`@xyflow/react`) para los flujos, GrapesJS + MJML para el editor de correo, fuente Inter, estética tipo Linear/Vercel |
| Connector | PHP 8.1+ con tipos estrictos, PSR-4 y Composer con dependencias prefijadas (Strauss), WordPress 6.4+, WooCommerce 8.5+ (opcional), compatible con HPOS y el checkout de bloques, Action Scheduler; admin en React + TS + Vite (`@kucrut/vite-for-wp`) |
| Tracker | TypeScript sin dependencias, compilado a IIFE con esbuild |
| Infraestructura | VPS Hetzner **dedicado al hub** (no el de ServerAvatar de los clientes), Docker Compose, Caddy (TLS automático, también para los dominios de seguimiento de cada cliente), Cloudflare delante del panel, copias en Hetzner Storage Box o S3, monitoreo con Better Stack |

¿Por qué Laravel y no un plugin de WordPress para el hub? Por el volumen de eventos, las colas de larga duración, el tiempo real de la bandeja y el aislamiento entre clientes. Un WordPress multi-cliente no aguanta eso. El conocimiento de PHP se reutiliza.

---

## 5. Hoja de ruta por fases

Cada fase se puede vender sola. No se empieza una fase sin cerrar los criterios de «Listo cuando» de la anterior.

### Fase 0 — Cimientos
Monorepo, Docker local, autenticación con 2FA, cuentas, espacios de trabajo y roles. Conexión del sitio (handshake), endpoint de ingesta, ClickHouse, outbox del plugin, sincronización de catálogo e importación histórica (backfill).
**Listo cuando:** un WooCommerce de prueba con 10.000 pedidos generados se conecta en menos de 2 minutos, el backfill completo termina sin errores, y los conteos de pedidos y la venta total coinciden al 100% con WooCommerce.

### Fase 1 — Conocer al cliente (datos y medición)
Tracker, resolución de identidad, perfil 360, constructor de segmentos, RFM, tableros de analítica, conversiones desde el servidor (Meta, Google, TikTok), agrupación de canales y atribución, encuesta «¿cómo nos conociste?».
**Listo cuando:** el tablero responde en menos de 1,5 s con un año de datos, Meta muestra la deduplicación correcta entre píxel y servidor, y el informe semanal por correo llega al dueño.

### Fase 2 — Mensajería y automatizaciones
Canales (correo SES, WhatsApp Cloud API + Marketing Messages API, Zender limitado), plantillas, constructor de flujos, los 20 flujos prediseñados, campañas, motor de consentimiento, horarios y frecuencia, enlaces rastreados, atribución de ingresos por mensaje y grupos de control.
**Listo cuando:** el flujo de carrito abandonado recupera pedidos en la tienda de prueba de punta a punta, ningún mensaje de marketing sale un domingo, un festivo o fuera de horario en Colombia (con prueba automática), y cada pedido recuperado aparece atribuido.

### Fase 3 — Captación y fidelización
Formularios y popups, quiz, widget de WhatsApp con atribución, puntos, VIP, saldo, referidos, afiliados, reseñas, avisos de stock y de precio, recomendaciones, audiencias hacia Meta y Google.
**Listo cuando:** un visitante anónimo deja su WhatsApp en un popup, recibe el cupón, compra, gana puntos, deja una reseña con foto y refiere a un amigo. Todo queda en su perfil y atribuido.

### Fase 4 — Inteligencia avanzada e IA
Predicciones (CLV, riesgo de abandono, próxima compra), afinidad de productos, motor de hallazgos, analista semanal con IA, «Pregúntale a tus datos», copiloto de campañas, bandeja de WhatsApp con agente de ventas con IA.
**Listo cuando:** las predicciones se validan contra un periodo de reserva y la app muestra su precisión, ninguna cifra de un texto de IA falta en los hechos (prueba automática), y el agente resuelve preguntas de catálogo sin inventar precios ni stock.

### Fase 5 — Servicios, agencia y escala
Puntuación de leads, agenda de citas, conversiones offline de leads ganados, cotizador, panel de agencia, reportes mensuales en PDF con marca, marca blanca, planes y facturación, API pública y servidor MCP, Instagram y Messenger en la bandeja.
**Listo cuando:** un sitio corporativo sin WooCommerce usa el producto completo para leads y la agencia ve a todos sus clientes en un tablero.

---

## 6. Cómo trabajar (instrucciones para Claude Code)

- **La especificación manda.** Si el código y `docs/` no coinciden, para y pregunta, o actualiza el documento en el mismo commit con el motivo.
- **Contrato de eventos:** cualquier cambio sube `schema_version`, actualiza `docs/eventos.md` y los JSON Schema compartidos (`hub/resources/schemas/events/`), y agrega pruebas de contrato en el hub y en el connector.
- **Pruebas:** Pest en el hub, PHPUnit con la suite de WordPress en el connector, Vitest en frontend y tracker, Playwright de punta a punta contra un WooCommerce de prueba (`wp-env`).
- **Calidad:** Larastan nivel 8, Laravel Pint, PHPCS (WordPress-Extra) en el connector, ESLint, TypeScript `strict`. CI en GitHub Actions; nada entra a `main` en rojo.
- **Migraciones:** nunca edites una migración ya aplicada; crea una nueva.
- **Datos personales:** nunca se escriben en logs en claro. Usa `Pii::mask()` en el hub y `Igr\Pii::mask()` en el plugin.
- **Datos de prueba:** `php artisan igr:seed-demo` y `wp igr demo-store` generan una tienda colombiana creíble (2.000 clientes, 10.000 pedidos en COP, ciudades reales, festivos, pagos PSE, Nequi y contraentrega) para probar analítica y flujos.
- **Commits:** Conventional Commits, con la descripción en español.
- **Textos de interfaz:** todo pasa por i18n (`__()` en PHP, `t()` en React) desde el primer día.
- **Cada fase cierra** con un recorrido manual grabado y una nota en `docs/CHANGELOG.md`.

---

## 7. Glosario

| Término | Significado |
|---|---|
| Cuenta (`account`) | Quien paga: la agencia o un cliente directo |
| Espacio de trabajo (`workspace`) | Un negocio; puede tener varios sitios |
| Sitio (`site`) | Una instalación de WordPress conectada |
| Contacto (`contact`) | Una persona: visitante identificado, lead o cliente |
| Evento | Algo que pasó, con fecha y propiedades (ver `docs/eventos.md`) |
| Flujo | Automatización que reacciona a eventos o segmentos |
| Campaña | Envío puntual a un segmento |
| Hallazgo | Problema u oportunidad detectada automáticamente, con impacto estimado y acción sugerida |
| Ventana legal | Franja horaria en la que la ley permite mensajes comerciales en el país del contacto |
