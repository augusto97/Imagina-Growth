<?php

/*
 * Textos de las estrategias (docs/diseno/rediseno-v2.md §6), en español neutro, en segunda persona y sin jerga.
 * `items.<clave>`: nombre (el resultado), promesa (una línea) y cómo funciona (3 pasos); `services` lleva la versión
 * para un negocio que solo vende servicios cuando cambia. lang/es_MX y lang/es_VE solo sobrescriben lo que cambia.
 */
return [
    'goals' => [
        'recover_sales' => ['name' => 'Recuperar ventas', 'description' => 'Cierra las ventas que casi se pierden.'],
        'sell_again' => ['name' => 'Volver a vender', 'description' => 'Haz que tus clientes compren otra vez.'],
        'loyalty' => ['name' => 'Fidelizar', 'description' => 'Premia a quien vuelve y convierte a tus clientes en tus vendedores.'],
        'capture' => ['name' => 'Captar contactos', 'description' => 'Convierte visitas en personas a las que puedes escribirles.'],
        'reputation' => ['name' => 'Reputación', 'description' => 'Consigue reseñas y escucha a tus clientes.'],
        'attention' => ['name' => 'Atención', 'description' => 'Responde rápido, a cualquier hora, sin que se te escape nadie.'],
        'ads' => ['name' => 'Anuncios', 'description' => 'Que tus anuncios aprendan de tus ventas reales.'],
        'services' => ['name' => 'Servicios', 'description' => 'Convierte más contactos en clientes.'],
    ],

    'items' => [
        'abandoned_cart' => [
            'name' => 'Recupera carritos abandonados',
            'promise' => 'Cuando alguien deja productos en el carrito, le escribimos para que termine su compra.',
            'steps' => [
                'Alguien agrega productos al carrito y se va sin comprar.',
                'A la hora le llega un WhatsApp (o un correo) con su carrito listo para terminar la compra.',
                'Si no vuelve, al día siguiente recibe un recordatorio con un cupón. Si compra, no le escribimos más.',
            ],
        ],
        'abandoned_checkout' => [
            'name' => 'Recupera pagos que quedaron a medias',
            'promise' => 'Quien empezó a pagar y no terminó recibe un recordatorio para completar su pedido.',
            'steps' => [
                'Un cliente llega al pago y no termina el pedido.',
                'A la media hora le recordamos por WhatsApp o correo que su pedido lo espera.',
                'Al día siguiente, un último recordatorio. Si compra, el mensaje deja de salir.',
            ],
        ],
        'payment_pending' => [
            'name' => 'Cobra los pagos pendientes',
            'promise' => 'Recuerda a tus clientes pagar los pedidos por transferencia, PSE o Efecty antes de que se cancelen.',
            'steps' => [
                'Un cliente hace un pedido y queda pendiente de pago.',
                'A las 2 horas recibe un recordatorio con el enlace para pagar.',
                'A las 24 horas, otro aviso antes de que el pedido se cancele.',
            ],
        ],
        'cod_confirm' => [
            'name' => 'Confirma los pedidos contraentrega',
            'promise' => 'Antes de despachar, tu cliente confirma su pedido contraentrega con un toque por WhatsApp.',
            'steps' => [
                'Entra un pedido para pagar contraentrega.',
                'Tu cliente recibe un mensaje con los botones «Confirmo» y «Cancelar».',
                'Si en 12 horas no responde, te avisamos para que lo llames antes de despachar.',
            ],
        ],
        'browse_abandonment' => [
            'name' => 'Recuérdales lo que estuvieron mirando',
            'promise' => 'Quien vio un producto varias veces sin comprarlo recibe un correo con ese producto y otras opciones.',
            'steps' => [
                'Un cliente mira el mismo producto dos o más veces en tres días.',
                'No lo agrega al carrito ni compra.',
                'Le enviamos un correo con ese producto y otros parecidos.',
            ],
        ],
        'second_purchase' => [
            'name' => 'Consigue la segunda compra',
            'promise' => 'A los clientes que compraron una vez les recomendamos lo siguiente justo cuando suelen volver.',
            'steps' => [
                'Medimos cuántos días tardan tus clientes en volver a comprar.',
                'Cuando alguien que compró una vez pasa ese tiempo, le escribimos.',
                'Le recomendamos lo que suelen comprar después quienes compraron lo mismo.',
            ],
        ],
        'replenishment' => [
            'name' => 'Avisa antes de que se les acabe',
            'promise' => 'Unos días antes de que tu cliente suela volver a comprar, le recordamos reponer su producto.',
            'steps' => [
                'Calculamos cuándo le toca volver a comprar a cada cliente.',
                'Cinco días antes le escribimos «se te está acabando».',
                'Con un enlace para volver a pedir lo mismo en un clic.',
            ],
        ],
        'winback' => [
            'name' => 'Recupera a los clientes que se están yendo',
            'promise' => 'Cuando un buen cliente deja de comprar, le escribimos un mensaje personal y, si no vuelve, una oferta.',
            'steps' => [
                'Detectamos a los buenos clientes que dejaron de comprar.',
                'Les enviamos un mensaje personal para retomar el contacto.',
                'Si no vuelven en tres días, reciben un cupón para regresar.',
            ],
        ],
        'ai_reactivate' => [
            'name' => 'Despierta a tus clientes dormidos con IA',
            'promise' => 'La IA escribe a cada cliente dormido un mensaje personal según lo último que habló contigo y lo que compró.',
            'steps' => [
                'Cuando un cliente lleva mucho tiempo sin comprar, entra a esta estrategia.',
                'La IA lee su última conversación y sus compras, y escribe un mensaje que termina con una pregunta fácil.',
                'Sale solo a quien aceptó recibir mensajes, en horario permitido. Sin una oferta tuya, no ofrece descuentos.',
            ],
            'services' => [
                'promise' => 'La IA escribe a cada cliente que dejó de escribirte un mensaje personal según lo último que hablaron.',
                'steps' => [
                    'Cuando un cliente lleva mucho tiempo sin contratarte, entra a esta estrategia.',
                    'La IA lee su última conversación y escribe un mensaje que termina con una pregunta fácil.',
                    'Sale solo a quien aceptó recibir mensajes, en horario permitido. Sin una oferta tuya, no ofrece descuentos.',
                ],
            ],
        ],
        'back_in_stock' => [
            'name' => 'Avisa cuando vuelva o baje de precio',
            'promise' => 'Quien pidió que le avisaran recibe el mensaje apenas el producto vuelve o baja de precio.',
            'steps' => [
                'En un producto agotado aparece el botón «Avísame cuando vuelva».',
                'Tu cliente deja su WhatsApp o su correo.',
                'Apenas vuelve el stock o baja el precio, le avisamos una sola vez.',
            ],
        ],
        'loyalty_points' => [
            'name' => 'Premia con puntos y niveles',
            'promise' => 'Tus clientes ganan puntos en cada compra, suben de nivel y los cambian por descuentos.',
            'steps' => [
                'Cada compra suma puntos en la cuenta de tu cliente.',
                'Quien más compra sube a Plata y a Oro, con más puntos por compra.',
                'Cambian los puntos por descuentos y les avisamos antes de que venzan.',
            ],
        ],
        'birthday' => [
            'name' => 'Celebra su cumpleaños',
            'promise' => 'El día de su cumpleaños, tu cliente recibe un regalo para usar en tu tienda.',
            'steps' => [
                'Tu cliente nos deja su fecha de cumpleaños.',
                'Ese día, a una hora permitida, le enviamos un saludo.',
                'Con un cupón de regalo para usar en tu tienda.',
            ],
        ],
        'referrals' => [
            'name' => 'Haz que tus clientes te recomienden',
            'promise' => 'Cada cliente tiene un enlace para invitar a sus amigos: el amigo recibe un descuento y quien invita, un premio.',
            'steps' => [
                'Tu cliente comparte su enlace por WhatsApp.',
                'Su amigo recibe un descuento en su primera compra.',
                'Cuando el amigo compra, quien lo invitó recibe su premio.',
            ],
        ],
        'welcome_popup' => [
            'name' => 'Convierte visitas en contactos',
            'promise' => 'Una ventana en tu web ofrece un regalo a cambio del WhatsApp o el correo, y le damos la bienvenida.',
            'steps' => [
                'Quien visita tu web por primera vez ve una ventana con un regalo.',
                'Deja su WhatsApp o su correo y recibe su cupón al instante.',
                'Los días siguientes le contamos tu historia y tus productos más vendidos.',
            ],
            'services' => [
                'promise' => 'Una ventana en tu web invita a dejar el WhatsApp o el correo para recibir información, y le damos la bienvenida.',
                'steps' => [
                    'Quien visita tu web ve una ventana que lo invita a dejar sus datos.',
                    'Deja su WhatsApp o su correo para que le escribas.',
                    'Le damos la bienvenida y te llega como un contacto nuevo.',
                ],
            ],
        ],
        'whatsapp_button' => [
            'name' => 'Botón de WhatsApp que mide sus ventas',
            'promise' => 'Un botón en tu web abre el chat contigo y te dice de qué página y anuncio vino cada conversación.',
            'steps' => [
                'Un botón de WhatsApp aparece en tu web.',
                'Tu cliente toca el botón y te escribe con un mensaje listo.',
                'Sabes de qué página y de qué anuncio vino, y cuánto vendió.',
            ],
            'services' => [
                'promise' => 'Un botón en tu web abre el chat contigo y te dice de qué página y anuncio vino cada consulta.',
                'steps' => [
                    'Un botón de WhatsApp aparece en tu web.',
                    'Tu cliente toca el botón y te escribe con un mensaje listo.',
                    'Sabes de qué página y de qué anuncio vino cada consulta.',
                ],
            ],
        ],
        'product_quiz' => [
            'name' => 'Recomienda productos con un quiz',
            'promise' => 'Unas preguntas cortas ayudan a cada visitante a encontrar su producto ideal y te dejan su contacto.',
            'steps' => [
                'Tu visitante responde unas preguntas cortas.',
                'Le mostramos los productos que mejor le quedan.',
                'Te deja su contacto para recibir el resultado y sus respuestas quedan en su perfil.',
            ],
        ],
        'review_request' => [
            'name' => 'Pide reseñas con foto',
            'promise' => 'Unos días después de la entrega le pedimos al cliente su opinión, con estrellas en un toque y foto opcional.',
            'steps' => [
                'Tu cliente recibe su pedido.',
                'Unos días después le pedimos su opinión por correo o WhatsApp.',
                'Califica con un toque, sube una foto si quiere y tú decides qué se publica.',
            ],
        ],
        'nps' => [
            'name' => 'Escucha a tus clientes',
            'promise' => 'Una pregunta corta mide qué tan felices están tus clientes y te avisa al instante si alguien quedó molesto.',
            'steps' => [
                'Dos semanas después de la entrega, preguntamos qué tanto te recomendarían.',
                'Tu cliente responde con un número del 0 al 10.',
                'Si alguien quedó molesto, te avisamos al instante para que lo llames.',
            ],
        ],
        'google_reviews' => [
            'name' => 'Consigue reseñas en Google',
            'promise' => 'Después de comprar, todos tus clientes ven el enlace para dejarte una reseña en Google.',
            'steps' => [
                'Pegas el enlace de tu perfil de Google.',
                'En la página de gracias aparece el botón para dejar la reseña.',
                'Se muestra a todos por igual, como lo pide Google.',
            ],
        ],
        'ai_sales_agent' => [
            'name' => 'Vende por WhatsApp con IA',
            'promise' => 'La IA responde al instante preguntas de productos, precios y envíos con los datos reales de tu tienda.',
            'steps' => [
                'Un cliente te escribe por WhatsApp.',
                'La IA consulta tu catálogo, el stock y tus políticas, y responde en segundos.',
                'Si pide una persona o tiene una queja, pasa la conversación a tu equipo.',
            ],
            'services' => [
                'promise' => 'La IA responde al instante preguntas sobre tus servicios, precios y horarios, y agenda citas.',
                'steps' => [
                    'Un cliente te escribe por WhatsApp.',
                    'La IA responde con la información de tu negocio y le ofrece horarios para agendar.',
                    'Si pide una persona o tiene una queja, pasa la conversación a tu equipo.',
                ],
            ],
        ],
        'inbox_assignment' => [
            'name' => 'Que ningún mensaje quede sin dueño',
            'promise' => 'Cada conversación nueva de WhatsApp, Instagram o Messenger le llega a alguien de tu equipo.',
            'steps' => [
                'Un cliente escribe por WhatsApp, Instagram o Messenger.',
                'La conversación le llega a alguien disponible de tu equipo, por turnos o por tema.',
                'Ves cuánto tardan en responder y cuánto vende cada persona.',
            ],
        ],
        'after_hours' => [
            'name' => 'Responde fuera de horario',
            'promise' => 'De noche y los fines de semana, la IA atiende a tus clientes y deja todo listo para tu equipo.',
            'steps' => [
                'Un cliente escribe cuando tu equipo no está.',
                'La IA responde con los datos reales de tu negocio y nunca inventa precios.',
                'En horario laboral las conversaciones vuelven a tu equipo.',
            ],
        ],
        'ads_conversions' => [
            'name' => 'Que tus anuncios aprendan de tus ventas',
            'promise' => 'Enviamos cada venta a Meta, Google y TikTok desde el servidor, para que tus anuncios lleguen a quien sí compra.',
            'steps' => [
                'Conectas tus cuentas de anuncios.',
                'Cada venta se envía a la plataforma, aunque el navegador bloquee el píxel.',
                'Tus campañas aprenden a buscar personas que sí compran.',
            ],
            'services' => [
                'promise' => 'Enviamos cada contacto y cada cliente ganado a Meta y Google, para que tus anuncios busquen a quien sí contrata.',
                'steps' => [
                    'Conectas tus cuentas de anuncios.',
                    'Cada contacto nuevo y cada cliente ganado se envía a la plataforma.',
                    'Tus campañas aprenden a buscar personas que sí te contratan.',
                ],
            ],
        ],
        'ads_audiences' => [
            'name' => 'Anuncia a quien más te importa',
            'promise' => 'Tus mejores clientes y los que están por irse se sincronizan solos con tus cuentas de anuncios.',
            'steps' => [
                'Elegimos a tus mejores clientes y a los que están por irse.',
                'Se envían a tus cuentas de anuncios y se actualizan solos.',
                'Solo van quienes aceptaron recibir publicidad.',
            ],
        ],
        'service_lead' => [
            'name' => 'Responde al instante a cada contacto',
            'promise' => 'Quien deja sus datos en tu web o te escribe recibe respuesta en segundos y un seguimiento hasta que avance.',
            'steps' => [
                'Alguien llena un formulario en tu web o te escribe por WhatsApp.',
                'Recibe una respuesta al instante y tu vendedor un aviso con su resumen.',
                'Si no responde, la IA le hace seguimiento los días 2, 10, 20 y 40.',
            ],
        ],
        'chat_qualification' => [
            'name' => 'Filtra a los curiosos en el chat',
            'promise' => 'La IA hace tus preguntas en la conversación y te pasa solo los contactos listos para comprar.',
            'steps' => [
                'Un contacto escribe por WhatsApp.',
                'La IA le hace tus preguntas de forma natural y guarda sus respuestas.',
                'Según lo que responde, te lo pasa a ti, a un vendedor o le envía el enlace que corresponde.',
            ],
        ],
        'appointment_reminders' => [
            'name' => 'Agenda citas con recordatorios',
            'promise' => 'Tus clientes reservan solos en tu página y reciben recordatorios para que no falten.',
            'steps' => [
                'Tu cliente elige un horario libre en tu página de reservas.',
                'Recibe la confirmación con el enlace para cambiar o cancelar.',
                'Un día antes y dos horas antes le recordamos la cita por WhatsApp o correo.',
            ],
        ],
        'renewal' => [
            'name' => 'Recuerda las renovaciones',
            'promise' => 'Quince días antes de que venza el servicio, tu cliente recibe un recordatorio y tu equipo un aviso si no renueva.',
            'steps' => [
                'Guardamos la fecha de renovación de cada cliente.',
                'Quince días antes le enviamos un recordatorio con tu oferta.',
                'Si un día antes no ha renovado, le avisamos a tu equipo.',
            ],
        ],
        'quote_calculator' => [
            'name' => 'Cotiza al instante en tu web',
            'promise' => 'Tu cliente calcula el precio en tu web y tú recibes su contacto listo para cerrar.',
            'steps' => [
                'Tu cliente responde unas preguntas sobre lo que necesita.',
                'Ve un precio estimado al instante.',
                'Te llega como un contacto nuevo con todo lo que pidió.',
            ],
        ],

        // Biblioteca ampliada (docs/diseno/rediseno-v2.md §11.4, flujos 21 a 35).
        'lead_nurture' => [
            'name' => 'Acompaña a quien todavía no decide',
            'promise' => 'Quien pidió información recibe 4 o 5 correos útiles en 3 semanas, hasta que agenda, cotiza o compra.',
            'steps' => [
                'Un contacto deja sus datos pero todavía no está listo.',
                'Recibe correos con consejos, casos y respuestas a las dudas de siempre.',
                'Apenas agenda, pide una cotización o compra, deja de recibirlos.',
            ],
        ],
        'quote_followup' => [
            'name' => 'Haz seguimiento a tus cotizaciones',
            'promise' => 'Una cotización sin respuesta recibe un WhatsApp, un correo y un último aviso, y tu vendedor se entera.',
            'steps' => [
                'Envías una cotización y el cliente no responde.',
                'Le escribimos por WhatsApp al día 2 y por correo al día 5.',
                'Al día 10 va un último aviso y le contamos a tu vendedor para que llame.',
            ],
        ],
        'appointment_confirmation' => [
            'name' => 'Confirma las citas antes de que lleguen',
            'promise' => 'Dos días antes, tu cliente confirma su cita con un toque; si no confirma, tu equipo se entera.',
            'steps' => [
                'Alguien agenda una cita contigo.',
                '48 horas antes le pedimos que la confirme con un botón.',
                'Si no confirma, avisamos a tu equipo para que llame y no pierdas el espacio.',
            ],
        ],
        'no_show_recovery' => [
            'name' => 'Recupera las citas perdidas',
            'promise' => 'Quien no llegó recibe un mensaje amable con el enlace para agendar otra vez el mismo día.',
            'steps' => [
                'Un cliente no llega a su cita.',
                'Ese mismo día le escribimos sin reproches.',
                'Con el enlace para elegir otro horario en un toque.',
            ],
        ],
        'post_service' => [
            'name' => 'Pide opinión después del servicio',
            'promise' => 'Después de atenderlo, tu cliente cuenta cómo le fue, te deja una reseña en Google y recibe su enlace para recomendarte.',
            'steps' => [
                'Terminas el servicio o la cita.',
                'Le preguntamos cómo le fue.',
                'Le pedimos la reseña en Google y le damos su enlace para recomendarte.',
            ],
        ],
        'periodic_service' => [
            'name' => 'Recuerda los controles y mantenimientos',
            'promise' => 'Cada cierto tiempo, tu cliente recibe el recordatorio de su control o mantenimiento con el enlace para agendar.',
            'steps' => [
                'Tu cliente se hace un control, una limpieza o un mantenimiento.',
                'Cuando se cumple el tiempo para el siguiente, le recordamos.',
                'Agenda en un toque desde el enlace del mensaje.',
            ],
        ],
        'event_registration' => [
            'name' => 'Llena tus eventos y clases',
            'promise' => 'Quien se inscribe a un evento o una clase recibe la confirmación, los recordatorios y la grabación.',
            'steps' => [
                'Alguien se inscribe a tu evento, webinar o clase de prueba.',
                'Recibe la confirmación y recordatorios un día y una hora antes.',
                'Después le enviamos la grabación o el siguiente paso.',
            ],
        ],
        'enrollment_followup' => [
            'name' => 'Convierte interesados en inscritos',
            'promise' => 'Quien pregunta por un curso recibe la información, testimonios y la fecha límite para inscribirse.',
            'steps' => [
                'Un interesado pregunta por un curso o programa.',
                'Le enviamos la información y lo que dicen quienes ya lo tomaron.',
                'Le recordamos la fecha límite antes de que se cierren las inscripciones.',
            ],
        ],
        'property_interest' => [
            'name' => 'Acompaña el interés por una propiedad',
            'promise' => 'Quien pregunta por una propiedad recibe opciones parecidas, la invitación a visitarla y seguimiento.',
            'steps' => [
                'Alguien pregunta por una propiedad.',
                'Le mostramos otras parecidas y lo invitamos a agendar la visita.',
                'Si no avanza, le hacemos seguimiento sin que se te olvide.',
            ],
        ],
        'lost_lead_revival' => [
            'name' => 'Vuelve a intentar con los que no cerraron',
            'promise' => 'Tres meses después de perder un contacto, le escribimos con una novedad para retomar la conversación.',
            'steps' => [
                'Un contacto no cerró contigo.',
                'Pasados 90 días, le escribimos con algo nuevo que contarle.',
                'Si responde, vuelve a tu lista para que lo atiendas.',
            ],
        ],
        'new_client_welcome' => [
            'name' => 'Dale la bienvenida a cada cliente nuevo',
            'promise' => 'Quien te contrata recibe qué esperar, cómo contactarte y los próximos pasos.',
            'steps' => [
                'Cierras con un cliente nuevo.',
                'Le enviamos la bienvenida con lo que va a pasar.',
                'Con tus datos de contacto y los próximos pasos.',
            ],
        ],
        'services_reactivation' => [
            'name' => 'Trae de vuelta a los clientes que no volvieron',
            'promise' => 'Quien lleva meses sin contratarte recibe un mensaje personal con una buena razón para volver.',
            'steps' => [
                'Un cliente deja de contratarte por varios meses.',
                'Le escribimos un mensaje personal.',
                'Con una razón para volver: una novedad, un control o una oferta tuya.',
            ],
        ],
        'cross_sell' => [
            'name' => 'Ofrece lo que combina con su compra',
            'promise' => 'Una semana después de comprar, tu cliente recibe los complementos de lo que compró.',
            'steps' => [
                'Tu cliente compra un producto.',
                'A los 7 días le mostramos lo que combina con su compra.',
                'Compra los complementos en un clic.',
            ],
        ],
        'delivery_check' => [
            'name' => 'Pregunta si su pedido llegó bien',
            'promise' => 'Dos días después de la entrega le preguntamos a tu cliente si todo llegó bien y te avisamos si hubo un problema.',
            'steps' => [
                'Tu cliente recibe su pedido.',
                'Dos días después le preguntamos si llegó bien.',
                'Si algo salió mal, te avisamos al instante para que lo resuelvas.',
            ],
        ],
        'first_purchase_anniversary' => [
            'name' => 'Celebra el aniversario de su primera compra',
            'promise' => 'Un año después de su primera compra, tu cliente recibe un detalle de agradecimiento.',
            'steps' => [
                'Guardamos la fecha de la primera compra de cada cliente.',
                'Al cumplir el año le escribimos para agradecerle.',
                'Con un detalle para que vuelva a comprarte.',
            ],
        ],
    ],

    'coming_soon' => 'Próximamente',
    'coming_soon_hint' => 'Esta estrategia llega pronto: su automatización todavía no está disponible.',

    'modules' => [
        'onsite' => 'captación en tu web',
        'loyalty' => 'fidelización',
        'referrals' => 'referidos',
        'reviews' => 'reseñas',
        'inbox' => 'bandeja de conversaciones',
        'ai' => 'inteligencia artificial',
        'ai_agent' => 'agente de ventas con IA',
        'services' => 'contactos de servicios',
        'bookings' => 'agenda de citas',
        'quotes' => 'cotizador',
        'api' => 'API',
        'mcp' => 'conexión con asistentes de IA',
        'agency_reports' => 'informes de agencia',
        'white_label' => 'marca propia',
    ],

    'requirements' => [
        'module' => ['label' => 'Incluido en tu plan: :module', 'missing' => 'Tu plan no incluye :module.', 'action' => 'Ver planes'],
        'site' => ['label' => 'Tu web conectada', 'missing' => 'Conecta tu web para activar esto.', 'action' => 'Conectar mi web'],
        'store' => ['label' => 'Tu tienda conectada', 'missing' => 'Conecta tu tienda WooCommerce para activar esto.', 'action' => 'Conectar mi tienda'],
        'history' => ['label' => 'Tu historial importado', 'missing' => 'Espera a que termine de importarse tu historial: así sabemos a quién escribirle.', 'action' => 'Ver la importación'],
        'channel' => ['label' => 'WhatsApp o correo conectado', 'missing' => 'Conecta WhatsApp o tu correo para que salgan los mensajes.', 'action' => 'Conectar un canal'],
        'email' => ['label' => 'Tu correo conectado', 'missing' => 'Conecta tu correo para que salgan los mensajes.', 'action' => 'Conectar el correo'],
        'whatsapp' => ['label' => 'WhatsApp conectado', 'missing' => 'Conecta tu WhatsApp para activar esto.', 'action' => 'Conectar WhatsApp'],
        'inbox_channel' => ['label' => 'WhatsApp, Instagram o Messenger conectado', 'missing' => 'Conecta WhatsApp, Instagram o Messenger para recibir conversaciones.', 'action' => 'Conectar un canal'],
        'wa_templates' => [
            'label' => 'Plantillas de WhatsApp aprobadas',
            'missing' => 'Faltan plantillas de WhatsApp aprobadas por Meta (:templates).',
            'optional' => 'Mientras Meta aprueba las plantillas de WhatsApp (:templates), los mensajes salen por correo.',
            'action' => 'Ver plantillas',
        ],
        'ai' => ['label' => 'La IA disponible', 'missing' => 'La IA no está disponible en este momento. Pide al equipo de la plataforma que la active.', 'action' => 'Ver la IA'],
        'ad_destination' => ['label' => 'Una cuenta de anuncios conectada', 'missing' => 'Conecta tu cuenta de Meta, Google o TikTok.', 'action' => 'Conectar anuncios'],
        'booking_service' => ['label' => 'Un servicio en tu agenda', 'missing' => 'Crea en la agenda el primer servicio que tus clientes pueden reservar.', 'action' => 'Crear un servicio'],
        'quote_calculator' => ['label' => 'Un cotizador listo', 'missing' => 'Arma tu primer cotizador con tus precios.', 'action' => 'Crear el cotizador'],
        'qualification_set' => ['label' => 'Tus preguntas de calificación', 'missing' => 'Escribe las preguntas que la IA hace en el chat.', 'action' => 'Escribir preguntas'],
        'agent_on' => ['label' => 'El agente de ventas con IA encendido', 'missing' => 'Prende primero el agente de ventas con IA: es quien hace las preguntas.', 'action' => 'Ver el agente'],
        'google_url' => ['label' => 'El enlace de tus reseñas en Google', 'missing' => 'Pega el enlace de tu perfil de Google para pedir reseñas.', 'action' => 'Agregar el enlace'],
        'wa_number' => ['label' => 'Tu número de WhatsApp', 'missing' => 'Conecta tu WhatsApp o escribe el número que abrirá el botón.', 'action' => 'Conectar WhatsApp'],
    ],

    'problems' => [
        'channel_down' => 'Un canal que usa quedó desconectado: reconéctalo para que los mensajes sigan saliendo.',
        'no_channel' => 'No hay un canal conectado para enviar los mensajes.',
        'template_rejected' => 'Meta rechazó o pausó una de sus plantillas de WhatsApp.',
        'site_down' => 'Tu web está desconectada: lo que esta estrategia muestra en el sitio no se está viendo.',
        'ai_unavailable' => 'La IA no está disponible: el agente no está respondiendo.',
        'destination_error' => 'La última entrega a la plataforma de anuncios falló.',
    ],

    'potential' => [
        'estimate' => 'Con una recuperación conservadora del :rate, serían unos :estimated.',
        'abandoned_carts' => '{1} En los últimos :days días hubo 1 carrito abandonado por :value.|[2,*] En los últimos :days días hubo :count carritos abandonados por :value.',
        'abandoned_checkouts' => '{1} En los últimos :days días 1 cliente empezó a pagar y no terminó (:value).|[2,*] En los últimos :days días :count clientes empezaron a pagar y no terminaron (:value).',
        'pending_payments' => '{1} En los últimos :days días 1 pedido quedó sin pagar por :value.|[2,*] En los últimos :days días :count pedidos quedaron sin pagar por :value.',
        'cod_pending' => '{1} 1 pedido contraentrega por :value espera confirmación.|[2,*] :count pedidos contraentrega por :value esperan confirmación.',
        'one_time_buyers' => '{1} 1 cliente compró una vez y no ha vuelto después de :days días.|[2,*] :count clientes compraron una vez y no han vuelto después de :days días.',
        'due_soon' => '{1} 1 cliente debería volver a comprar en los próximos :days días.|[2,*] :count clientes deberían volver a comprar en los próximos :days días.',
        'at_risk' => '{1} 1 buen cliente dejó de comprar; te ha comprado :value.|[2,*] :count buenos clientes dejaron de comprar; te han comprado :value.',
        'dormant' => '{1} 1 cliente lleva mucho tiempo sin comprar.|[2,*] :count clientes llevan mucho tiempo sin comprar.',
        'repeat_customers' => '{1} 1 cliente ya te compró más de una vez.|[2,*] :count clientes ya te compraron más de una vez.',
        'happy_customers' => '{1} 1 de tus mejores clientes puede recomendarte.|[2,*] :count de tus mejores clientes pueden recomendarte.',
        'birthdays' => '{1} 1 cliente cumple años este mes.|[2,*] :count clientes cumplen años este mes.',
        'customers' => '{1} Tienes 1 cliente para tus anuncios.|[2,*] Tienes :count clientes para tus anuncios.',
        'recent_customers' => '{1} 1 cliente compró en los últimos :days días.|[2,*] :count clientes compraron en los últimos :days días.',
        'watchers' => '{1} 1 persona espera que le avises de un producto.|[2,*] :count personas esperan que les avises de un producto.',
        'unreviewed_orders' => '{1} 1 pedido entregado en los últimos :days días no tiene reseña.|[2,*] :count pedidos entregados en los últimos :days días no tienen reseña.',
        'paid_orders' => '{1} 1 venta por :value en los últimos :days días puede enseñarle a tus anuncios.|[2,*] :count ventas por :value en los últimos :days días pueden enseñarle a tus anuncios.',
        'inbound' => '{1} 1 mensaje te llegó en los últimos :days días.|[2,*] :count mensajes te llegaron en los últimos :days días.',
        'after_hours_inbound' => '{1} 1 mensaje llegó de noche o en fin de semana en los últimos :days días.|[2,*] :count mensajes llegaron de noche o en fin de semana en los últimos :days días.',
        'unassigned' => '{1} 1 conversación abierta no tiene a nadie asignado.|[2,*] :count conversaciones abiertas no tienen a nadie asignado.',
        'unanswered_leads' => '{1} 1 contacto de los últimos :days días sigue sin respuesta.|[2,*] :count contactos de los últimos :days días siguen sin respuesta.',
        'chat_leads' => '{1} 1 contacto llegó por el chat en los últimos :days días.|[2,*] :count contactos llegaron por el chat en los últimos :days días.',
        'open_leads' => '{1} Tienes 1 contacto abierto que todavía no decide.|[2,*] Tienes :count contactos abiertos que todavía no deciden.',
        'lost_leads' => '{1} 1 contacto que no cerró hace más de :days días puede volver a intentarse.|[2,*] :count contactos que no cerraron hace más de :days días pueden volver a intentarse.',
        'no_shows' => '{1} 1 cita no se cumplió en los últimos :days días.|[2,*] :count citas no se cumplieron en los últimos :days días.',
        'upcoming_appointments' => '{1} Tienes 1 cita en los próximos :days días.|[2,*] Tienes :count citas en los próximos :days días.',
        'renewals' => '{1} 1 cliente renueva en los próximos :days días.|[2,*] :count clientes renuevan en los próximos :days días.',
        'visitors' => '{1} 1 persona visitó tu web en los últimos :days días.|[2,*] :count personas visitaron tu web en los últimos :days días.',
    ],

    'preview' => [
        'ai_message' => 'La IA escribe este mensaje para cada persona con sus datos reales: nunca inventa cifras ni ofrece descuentos que no diste.',
        'wa_template_needed' => 'Usará tu plantilla de WhatsApp aprobada por Meta.',
        'loyalty_earn' => '{1} Ganan 1 punto por cada :amount de compra.|[2,*] Ganan :points puntos por cada :amount de compra.',
        'loyalty_reward' => 'Con :points puntos reciben :value de descuento.',
        'loyalty_tiers' => 'Niveles: Plata desde :silver al año y Oro desde :gold, con más puntos por compra.',
        'referral_friend' => 'El amigo recibe :reward en su primera compra.',
        'referral_referrer' => 'Quien invita recibe :reward cuando su amigo compra.',
        'nps_alert' => 'Si alguien da 6 o menos, te avisamos al instante y queda una tarea para llamarlo.',
        'google_thanks' => 'En la página de gracias aparece el botón para dejar una reseña en Google.',
        'google_everyone' => 'Se muestra a todos los clientes por igual, como lo pide Google.',
        'agent_always' => 'Responde todo el día los mensajes nuevos de WhatsApp.',
        'agent_out_of_hours' => 'Responde de lunes a viernes antes de las 8:00 y después de las 18:00, y todo el fin de semana.',
        'agent_facts' => 'Consulta precios y stock antes de responder: nunca los inventa.',
        'agent_handoff' => 'Si el cliente pide una persona o tiene una queja, pasa la conversación a tu equipo.',
        'inbox_assign' => 'Cada conversación nueva le llega a alguien disponible de tu equipo.',
        'inbox_rules' => 'También puedes repartir por tema: ventas, soporte o quejas.',
        'ads_server' => 'Cada venta se envía desde el servidor, aunque el navegador bloquee el píxel.',
        'ads_platforms' => 'Conectado a: :platforms.',
        'ads_connect' => 'Conecta Meta, Google o TikTok para empezar.',
        'audiences_seed' => 'Tus mejores clientes, para encontrar personas parecidas.',
        'audiences_at_risk' => 'Los que están por irse, para recordarles tu marca.',
        'audiences_consent' => 'Solo van quienes aceptaron recibir publicidad.',
        'booking_reminders' => 'Recordatorios :hours horas antes de cada cita.',
        'booking_buttons' => 'Por WhatsApp con botones para confirmar o reprogramar, o por correo.',
        'quote_price' => 'Tu cliente ve un precio estimado en segundos.',
        'quote_lead' => 'Para ver el precio deja su contacto, y te llega como un contacto nuevo.',
    ],

    // «Lo que va a crear» en el detalle de una estrategia (una línea por parte).
    'parts' => [
        'flow' => 'Automatización «:name»',
        'widget' => 'Un formulario en tu web',
        'loyalty_program' => 'Tu programa de puntos y niveles',
        'referral_program' => 'Tu programa de referidos',
        'review_request' => 'Pedidos de reseña después de cada entrega',
        'nps_survey' => 'La encuesta de satisfacción (NPS)',
        'google_reviews' => 'El botón de reseñas en Google en tu página de gracias',
        'product_watches' => 'Los avisos de stock y de precio en tus productos',
        'sales_agent' => 'El agente de ventas con IA en WhatsApp',
        'inbox_assignment' => 'El reparto automático de conversaciones a tu equipo',
        'ad_destinations' => 'El envío de tus ventas a las plataformas de anuncios',
        'audiences' => 'Audiencias de tus clientes en tus anuncios',
        'qualification' => 'Las preguntas de calificación en el chat',
        'booking_reminders' => 'Los recordatorios de tus citas',
        'quote_calculators' => 'Tu cotizador en la web',
    ],

    'widgets' => [
        'popup' => 'Bienvenida con regalo',
        'wa_widget' => 'Botón de WhatsApp',
        'quiz' => 'Quiz de productos',
        'popup_services_title' => '¿Te ayudamos?',
        'popup_services_body' => 'Déjanos tu WhatsApp y te contamos cómo podemos ayudarte.',
        'popup_services_cta' => 'Quiero información',
        'popup_services_success' => '¡Gracias! Te escribimos muy pronto.',
        'popup_email_body' => 'Déjanos tu correo y te enviamos el cupón al instante.',
    ],

    'loyalty' => [
        'silver' => 'Plata',
        'gold' => 'Oro',
        'reward' => 'Descuento de :value',
    ],

    'qualification' => [
        'name' => 'Preguntas recomendadas',
        'need' => '¿Qué necesitas exactamente?',
        'when' => '¿Para cuándo lo necesitas?',
        'where' => '¿En qué ciudad o zona estás?',
    ],

    'errors' => [
        'needs_setup' => 'Antes de activar esta estrategia falta resolver lo siguiente.',
        'plan_required' => 'Tu plan no incluye esta estrategia. Cámbialo en Plan y facturación para activarla.',
        'flow_pending' => 'Falta terminar algo de la automatización antes de activarla: revisa los pendientes.',
        'widget_pending' => 'Falta completar algo de lo que se muestra en tu web antes de activarlo.',
        'qualification_pending' => 'No se pudieron crear las preguntas recomendadas: escríbelas en Leads → Calificación.',
    ],
];
