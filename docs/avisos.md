# Avisos: qué se envía, a quién y qué pasa si falta algo

> Lote 0.4.0. Auditoría de todos los avisos de la plataforma y cómo se ve lo que salió y lo que no. Código: `hub/app/Domain/Notices`,
> `Bookings\Actions\AppointmentNotifier`, `Bookings\Support\AppointmentNoticeLog`, `Messaging\Channels\Drivers\PlatformMailDriver`.

## 1. Auditoría (estado antes y después del lote 0.4.0)

| Aviso | Lo dispara | Canal | Necesita | Si falta… antes | Ahora |
|---|---|---|---|---|---|
| Confirmación de la cita al cliente | `BookAppointment` → `AppointmentNotifier` | WhatsApp (plantilla elegida en la agenda) o correo | WhatsApp oficial + plantilla aprobada, o correo del cliente + canal de correo del negocio | **No salía nada y nadie se enteraba** (sin canal de correo el despachador negaba `channel_unavailable`); la página decía «Te enviamos…» igual | Sin correo propio sale por el **correo de la plataforma** (transaccional, con el nombre del negocio y respuestas a su contacto). Lo que no sale queda en `notice_log` con el motivo; la página solo dice «te enviamos» por el canal que salió |
| Recordatorios 24 h y 2 h, cambio y cancelación | `igr:bookings:tick`, `ChangeAppointment` | igual que la confirmación | igual | igual (fallaban en silencio) | igual que la confirmación |
| **Nueva cita / movida / cancelada / confirmada al equipo** | — | — | — | **No existía** | `AppointmentTeamListener` (eventos `appointment.*`) → `NotifyAppointmentTeam` → `NotifyTeam`: campana, correo de la plataforma, navegador y WhatsApp según «Mis avisos»; a quien atiende (por correo si no usa el panel) y a dueños/administradores |
| Lead nuevo / caliente / calificado | `CaptureLead`, `LeadWriter`, `ApplyQualificationRoute` → `AlertSeller` | WhatsApp `team_alert` al vendedor o correo por el canal de correo del negocio | reglas de avisos encendidas y canal | Sin canal de correo no salía; con la regla «lead nuevo» apagada, nada | Además la **campana** (aunque la regla esté apagada); el correo de `AlertSeller` ya tiene el respaldo de la plataforma (es de utilidad). Queda en `notice_log` |
| Conversación asignada | `AssignConversation` | navegador (Web Push si hay llaves VAPID) | VAPID + suscripción del navegador | Sin VAPID (`LogPushNotifier`) nadie se enteraba | Además la **campana** |
| Conexión por vencer, vencida o desconectada | `ConnectionAlerts` | correo de la plataforma a dueños y administradores | correo de la plataforma configurado | Bien (ya usaba el correo de la plataforma) | Además la **campana** |
| Resultado del cotizador, gracias de la landing | la página pública | se muestra en pantalla | — | Bien (no se envía mensaje) | Sin cambios |
| Reseñas y NPS | `igr:reviews:send` (`ChannelCascade`) | correo, WhatsApp o SMS | canal y consentimiento | Bien (cascada por canal) | Sin cambios |
| Referidos (premio, enlace de creador) | M14 | correo de la plataforma | correo de la plataforma | Bien | Sin cambios |
| Plan, facturación, uso al 80/100 %, informe semanal y mensual | M21, `igr:report:weekly`, `igr:agency:monthly-reports` | correo de la plataforma | correo de la plataforma | Bien | Sin cambios |
| Invitaciones y recuperar contraseña | `InviteToWorkspace`, Fortify | correo de la plataforma (y enlace copiable) | correo de la plataforma | Bien | Sin cambios |

## 2. Centro de avisos y «Mis avisos»

- Campana en la barra lateral (y en la barra del celular) con los no leídos; el centro de avisos (perezoso) lista los últimos 40, marca
  leídos y lleva a la cita (`agenda?fecha=…&cita=ap_…`, que abre sus avisos), al lead, a la conversación o a la conexión.
- Tiempo real con Reverb por `workspace.{ws_…}.notices.{usr_…}` (`NoticeCreated`, solo el ID); sin Reverb, consulta cada minuto.
- «Mis avisos» por persona (`notice_preferences`): citas (en la app, correo, navegador, WhatsApp; de todo el negocio o solo las mías),
  leads (en la app, navegador; todo o solo los míos), conversaciones (en la app) y conexiones (en la app, solo dueños y administradores).
  De fábrica: dueños y administradores reciben todo; los demás, solo lo suyo. WhatsApp al equipo necesita un WhatsApp oficial y la
  plantilla `team_alert` aprobada; el número es el de «Mis avisos» o el de la ficha de vendedor.
- Una persona recibe cada aviso una sola vez (`dedupe` en la caché) y nunca el que provocó ella misma desde el panel.

## 3. Correo transaccional de respaldo

Si el espacio de trabajo no tiene un canal de correo activo, un mensaje **transaccional o de utilidad** (nunca marketing) sale por el
correo de la plataforma (`provider = platform_mail`, sin `channel_account_id`): remitente visible = nombre del negocio, dirección = la de
la plataforma, «Responder a» = el contacto de `BusinessProfile` si es un correo o el del primer dueño. Pasa por `SendPolicy` como cualquier
otro (supresión incluida), cuenta en el uso del plan (`emails`) y solo aplica si el correo de la plataforma está configurado
(`PlatformMail::available`) y el plan incluye correo por la plataforma (`PlatformSending::emailAllowed`).

## 4. Qué se ve

- **Avisos de una cita** (Agenda → menú de la cita → «Ver avisos», o desde la campana): al cliente y al equipo, canal, estado vivo del
  mensaje y, si no salió, el motivo (`no_phone`, `no_whatsapp_channel`, `no_whatsapp_template`, `no_email`, `channel_unavailable`,
  `plan_no_platform_email`, `platform_mail_off`…) con «Arreglarlo».
- **Avisos de un lead** (ficha del lead): los del lead y los de sus citas.
- **«Así avisamos»** (Agenda → Página de reservas): cómo le llega la confirmación al cliente (WhatsApp, correo propio o de la plataforma),
  a quién del equipo y por dónde, y los arreglos: conectar WhatsApp, elegir la plantilla, conectar el correo, configurar el correo de la
  plataforma (solo administradores de la plataforma) y «Pedir el correo siempre» cuando no hay WhatsApp.
