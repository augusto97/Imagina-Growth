<?php

declare(strict_types=1);

namespace App\Domain\Campaigns\Support;

use App\Support\Holidays\Holidays;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Calendario comercial LATAM por país (modulos.md M10, rediseño v2 §11.5): las fechas que mueven ventas o consultas en
 * Colombia, México y Venezuela, calculadas por año con reglas (día fijo, n-ésimo día de la semana del mes, relativas a
 * la Pascua o al Black Friday), con ideas de campaña, a quién aplican (`audiences`: tienda, servicios o verticales) y
 * la campaña sugerida para cada uno (`campaigns`: plantilla de la galería con sus textos, canal y grupo de clientes).
 *
 * `lead` = días antes de la fecha en que conviene enviar (la campaña se programa ese día, `starts_communicating`);
 * `notice` = días antes del envío en que se avisa en «Recomendaciones» y «Para hoy» (14 por defecto).
 *
 * Las fechas que el organizador fija cada año sin una regla (Hot Sale de la AMVO, El Buen Fin, los CyberLunes de la
 * CCCE) o las temporadas que se mueven se corrigen desde Plataforma → Calendario comercial
 * (`commercial_calendar_overrides`): una fecha propia para ese año y país, o «no se hace». Sin excepción rige la regla
 * de los últimos años **[verificar cada año]**.
 */
final class CommercialCalendar
{
    /** Días antes del envío en que se avisa, si el momento no dice otra cosa. */
    public const NOTICE_DAYS = 14;

    public const COUNTRIES = ['CO', 'MX', 'VE'];

    /**
     * rule: fixed {month, day} · nth {month, n, dow (1 = lunes … 7 = domingo; n = -1 es el último)} ·
     * black_friday {offset (días)} · buen_fin · hot_sale · easter {offset (días)}. `days` = duración.
     *
     * @var array<string, array{name: string, countries: list<string>, rule: array<string, int|string>, days: int, lead: int, notice?: int, ideas: list<string>,
     *     audiences: list<string>, campaigns: list<array{for: string, template: string, channel?: string, segment?: string, copy?: array<string, string>}>}>
     */
    private const EVENTS = [
        'ano_nuevo' => ['name' => 'Año Nuevo', 'countries' => ['CO', 'MX', 'VE'], 'rule' => ['type' => 'fixed', 'month' => 1, 'day' => 1], 'days' => 1, 'lead' => 3, 'notice' => 10,
            'ideas' => ['Saludo de año nuevo a los clientes de todo el año', 'Lo que viene este año en el negocio'],
            'audiences' => ['store', 'services'],
            'campaigns' => [
                ['for' => 'services', 'template' => 'gal_seasonal', 'copy' => ['subject' => 'Feliz año nuevo de parte de {{ tienda.nombre }}', 'headline' => '¡Feliz año nuevo, {{ contacto.nombre | default: "hola" }}!', 'intro' => 'Gracias por confiar en nosotros este año. Te esperamos en el que empieza.']],
                ['for' => 'store', 'template' => 'gal_seasonal', 'copy' => ['subject' => 'Feliz año nuevo de parte de {{ tienda.nombre }}', 'headline' => '¡Feliz año nuevo, {{ contacto.nombre | default: "hola" }}!', 'intro' => 'Gracias por comprarnos este año. Tenemos novedades para el que empieza.']],
            ]],
        'inscripciones_enero' => ['name' => 'Inscripciones de enero', 'countries' => ['CO', 'MX', 'VE'], 'rule' => ['type' => 'fixed', 'month' => 1, 'day' => 8], 'days' => 21, 'lead' => 0,
            'ideas' => ['Propósitos de año nuevo: aprender algo nuevo', 'Cupos limitados por grupo', 'Precio especial por inscribirse en enero'],
            'audiences' => ['education', 'health'],
            'campaigns' => [
                ['for' => 'education', 'template' => 'gal_promotion', 'copy' => ['subject' => 'Inscripciones abiertas: empieza el año aprendiendo', 'headline' => 'Este año, aprende algo nuevo', 'intro' => 'Abrimos las inscripciones de enero. Los cupos por grupo son limitados: reserva el tuyo.']],
                ['for' => 'health', 'template' => 'gal_promotion', 'copy' => ['subject' => 'Empieza el año cuidándote', 'headline' => 'Tu propósito de este año empieza aquí', 'intro' => 'Agenda tu primera cita del año y empieza a cuidarte desde enero.']],
            ]],
        'regreso_clases_a' => ['name' => 'Regreso a clases (calendario A)', 'countries' => ['CO'], 'rule' => ['type' => 'fixed', 'month' => 1, 'day' => 19], 'days' => 14, 'lead' => 14,
            'ideas' => ['Listas escolares armadas por grado', 'Envío gratis en compras de útiles', 'Combo morral + cuadernos'],
            'audiences' => ['store', 'education'],
            'campaigns' => [
                ['for' => 'store', 'template' => 'gal_promotion', 'copy' => ['subject' => 'Regreso a clases: todo listo en un solo pedido', 'headline' => 'Que no falte nada para el regreso a clases', 'intro' => 'Arma la lista escolar con nosotros y recíbela a tiempo.']],
                ['for' => 'education', 'template' => 'gal_reminder', 'copy' => ['subject' => 'Últimos cupos antes del regreso a clases', 'headline' => 'Asegura tu cupo antes de empezar', 'intro' => 'Se acerca el regreso a clases y los grupos se están llenando. Reserva tu cupo a tiempo.']],
            ]],
        'san_valentin' => ['name' => 'San Valentín', 'countries' => ['CO', 'MX', 'VE'], 'rule' => ['type' => 'fixed', 'month' => 2, 'day' => 14], 'days' => 1, 'lead' => 10,
            'ideas' => ['Regalos para la pareja por presupuesto', 'Planes para dos', 'Fecha límite de envío para llegar a tiempo'],
            'audiences' => ['store', 'hospitality', 'health'],
            'campaigns' => [
                ['for' => 'store', 'template' => 'gal_promotion', 'copy' => ['subject' => 'Ideas de regalo para San Valentín', 'headline' => 'Sorprende en San Valentín', 'intro' => 'Elegimos los regalos favoritos para este 14 de febrero. Pide a tiempo para que llegue.']],
                ['for' => 'hospitality', 'template' => 'gal_promotion', 'copy' => ['subject' => 'Un plan para dos este San Valentín', 'headline' => 'Celebren juntos', 'intro' => 'Reserva un plan para dos este 14 de febrero. Los cupos se agotan rápido.']],
                ['for' => 'health', 'template' => 'gal_promotion', 'copy' => ['subject' => 'Regala bienestar este San Valentín', 'headline' => 'Un regalo que se siente', 'intro' => 'Un tratamiento o una sesión para dos: el regalo perfecto para este 14 de febrero.']],
            ]],
        'declaracion_islr_ve' => ['name' => 'Declaración del ISLR', 'countries' => ['VE'], 'rule' => ['type' => 'fixed', 'month' => 3, 'day' => 1], 'days' => 31, 'lead' => 14,
            'ideas' => ['Asesoría para declarar a tiempo', 'Lista de documentos que se necesitan'],
            'audiences' => ['professional'],
            'campaigns' => [
                ['for' => 'professional', 'template' => 'gal_reminder', 'copy' => ['subject' => 'Declara el ISLR a tiempo y sin estrés', 'headline' => 'Se acerca el plazo del ISLR', 'intro' => 'Te ayudamos a preparar tu declaración antes del 31 de marzo. Agenda una cita.']],
            ]],
        'semana_santa' => ['name' => 'Semana Santa', 'countries' => ['CO', 'MX', 'VE'], 'rule' => ['type' => 'easter', 'offset' => -7], 'days' => 8, 'lead' => 21,
            'ideas' => ['Reservas para el puente', 'Revisión del carro antes de viajar', 'Citas antes de las vacaciones'],
            'audiences' => ['hospitality', 'automotive', 'health'],
            'campaigns' => [
                ['for' => 'hospitality', 'template' => 'gal_promotion', 'copy' => ['subject' => 'Reserva tu Semana Santa', 'headline' => 'Tu descanso de Semana Santa', 'intro' => 'Las fechas se llenan rápido. Reserva desde ya tu plan para Semana Santa.']],
                ['for' => 'automotive', 'template' => 'gal_reminder', 'copy' => ['subject' => 'Revisa tu carro antes de viajar en Semana Santa', 'headline' => 'Viaja tranquilo', 'intro' => 'Agenda la revisión antes del viaje: frenos, llantas y aceite en un solo turno.']],
                ['for' => 'health', 'template' => 'gal_reminder', 'copy' => ['subject' => 'Agenda tu cita antes de Semana Santa', 'headline' => 'Antes de salir de vacaciones', 'intro' => 'Aprovecha para tu control antes de Semana Santa: hay horarios esta semana.']],
            ]],
        'declaracion_anual_mx' => ['name' => 'Declaración anual de personas físicas', 'countries' => ['MX'], 'rule' => ['type' => 'fixed', 'month' => 4, 'day' => 1], 'days' => 30, 'lead' => 14,
            'ideas' => ['Asesoría para declarar en abril', 'Deducciones personales que se pueden aplicar'],
            'audiences' => ['professional'],
            'campaigns' => [
                ['for' => 'professional', 'template' => 'gal_reminder', 'copy' => ['subject' => 'Presenta tu declaración anual a tiempo', 'headline' => 'Abril es mes de declaración anual', 'intro' => 'Te ayudamos a presentar tu declaración y a aprovechar tus deducciones. Agenda una cita.']],
            ]],
        'dia_nino_co' => ['name' => 'Día del Niño', 'countries' => ['CO'], 'rule' => ['type' => 'nth', 'month' => 4, 'n' => -1, 'dow' => 6], 'days' => 1, 'lead' => 10,
            'ideas' => ['Guía de regalos por edad', 'Planes en familia', 'Envoltura gratis'],
            'audiences' => ['store', 'hospitality', 'education'],
            'campaigns' => self::KIDS],
        'dia_nino_mx' => ['name' => 'Día del Niño', 'countries' => ['MX'], 'rule' => ['type' => 'fixed', 'month' => 4, 'day' => 30], 'days' => 1, 'lead' => 10,
            'ideas' => ['Guía de regalos por edad', 'Envoltura gratis'],
            'audiences' => ['store', 'hospitality', 'education'],
            'campaigns' => self::KIDS],
        'dia_madre' => ['name' => 'Día de la Madre', 'countries' => ['CO', 'VE'], 'rule' => ['type' => 'nth', 'month' => 5, 'n' => 2, 'dow' => 7], 'days' => 1, 'lead' => 15,
            'ideas' => ['Guía de regalos por presupuesto', 'Fecha límite de envío para llegar a tiempo', 'Recordatorio a quien compró regalo el año pasado'],
            'audiences' => ['store', 'health', 'hospitality'],
            'campaigns' => self::MOTHERS],
        'dia_madre_mx' => ['name' => 'Día de la Madre', 'countries' => ['MX'], 'rule' => ['type' => 'fixed', 'month' => 5, 'day' => 10], 'days' => 1, 'lead' => 15,
            'ideas' => ['Guía de regalos por presupuesto', 'Fecha límite de envío para llegar el 10 de mayo', 'Tarjeta de regalo de última hora'],
            'audiences' => ['store', 'health', 'hospitality'],
            'campaigns' => self::MOTHERS],
        'hot_sale' => ['name' => 'Hot Sale', 'countries' => ['MX'], 'rule' => ['type' => 'hot_sale'], 'days' => 9, 'lead' => 7,
            'ideas' => ['Acceso anticipado para clientes VIP', 'Descuento escalonado por día', 'Meses sin intereses'],
            'audiences' => ['store'],
            'campaigns' => [
                ['for' => 'store', 'template' => 'gal_promotion', 'copy' => ['subject' => 'Hot Sale en {{ tienda.nombre }}: acceso anticipado', 'headline' => 'Llega el Hot Sale', 'intro' => 'Prepárate: estos son los días con los mejores precios del año en línea. Te avisamos primero.']],
            ]],
        'dia_padre' => ['name' => 'Día del Padre', 'countries' => ['CO', 'MX', 'VE'], 'rule' => ['type' => 'nth', 'month' => 6, 'n' => 3, 'dow' => 7], 'days' => 1, 'lead' => 14,
            'ideas' => ['Regalos por pasatiempo', 'Combos de regalo con envoltura', 'Último día para pedir con entrega a tiempo'],
            'audiences' => ['store', 'hospitality', 'automotive'],
            'campaigns' => [
                ['for' => 'store', 'template' => 'gal_promotion', 'copy' => ['subject' => 'Ideas de regalo para el Día del Padre', 'headline' => 'Para papá, lo que más le gusta', 'intro' => 'Elegimos los regalos favoritos para el Día del Padre. Pide a tiempo para que llegue.']],
                ['for' => 'hospitality', 'template' => 'gal_promotion', 'copy' => ['subject' => 'Celebra con papá', 'headline' => 'Un plan para el Día del Padre', 'intro' => 'Reserva para celebrar con papá. Los cupos de ese fin de semana se llenan rápido.']],
                ['for' => 'automotive', 'template' => 'gal_promotion', 'copy' => ['subject' => 'Regálale a papá un mantenimiento', 'headline' => 'Un regalo que papá sí va a usar', 'intro' => 'Un mantenimiento o un lavado completo para su carro: el regalo práctico del Día del Padre.']],
            ]],
        'vacaciones_mitad_ano_co' => ['name' => 'Vacaciones de mitad de año', 'countries' => ['CO'], 'rule' => ['type' => 'fixed', 'month' => 6, 'day' => 20], 'days' => 28, 'lead' => 21,
            'ideas' => ['Chequeo antes de vacaciones', 'Revisión del carro antes del viaje', 'Cursos vacacionales'],
            'audiences' => self::VACATION_AUDIENCES,
            'campaigns' => self::VACATIONS],
        'prima_junio' => ['name' => 'Pago de prima (junio)', 'countries' => ['CO'], 'rule' => ['type' => 'fixed', 'month' => 6, 'day' => 30], 'days' => 1, 'lead' => 15,
            'ideas' => ['«Date un gusto con tu prima»: productos de mayor valor', 'Financiación o pago en cuotas', 'Recuperar carritos de productos caros'],
            'audiences' => ['store', 'services'],
            'campaigns' => self::BONUS],
        'vacaciones_verano_mx' => ['name' => 'Vacaciones de verano', 'countries' => ['MX'], 'rule' => ['type' => 'fixed', 'month' => 7, 'day' => 13], 'days' => 42, 'lead' => 21,
            'ideas' => ['Chequeo antes de vacaciones', 'Revisión del carro antes del viaje', 'Cursos de verano'],
            'audiences' => self::VACATION_AUDIENCES,
            'campaigns' => self::VACATIONS],
        'dia_nino_ve' => ['name' => 'Día del Niño', 'countries' => ['VE'], 'rule' => ['type' => 'nth', 'month' => 7, 'n' => 3, 'dow' => 7], 'days' => 1, 'lead' => 10,
            'ideas' => ['Guía de regalos por edad', 'Planes en familia'],
            'audiences' => ['store', 'hospitality', 'education'],
            'campaigns' => self::KIDS],
        'vacaciones_escolares_ve' => ['name' => 'Vacaciones escolares', 'countries' => ['VE'], 'rule' => ['type' => 'fixed', 'month' => 8, 'day' => 1], 'days' => 45, 'lead' => 21,
            'ideas' => ['Chequeo antes de vacaciones', 'Revisión del carro antes del viaje', 'Cursos vacacionales'],
            'audiences' => self::VACATION_AUDIENCES,
            'campaigns' => self::VACATIONS],
        'declaracion_renta_co' => ['name' => 'Declaración de renta (personas naturales)', 'countries' => ['CO'], 'rule' => ['type' => 'fixed', 'month' => 8, 'day' => 12], 'days' => 60, 'lead' => 14,
            'ideas' => ['Asesoría para declarar antes del plazo de la DIAN', 'Quién está obligado a declarar este año'],
            'audiences' => ['professional'],
            'campaigns' => [
                ['for' => 'professional', 'template' => 'gal_reminder', 'copy' => ['subject' => 'Declara renta a tiempo con nosotros', 'headline' => 'Empiezan los plazos de la DIAN', 'intro' => 'Revisamos si debes declarar y te ayudamos a presentarla antes de tu fecha. Agenda una cita.']],
            ]],
        'regreso_clases_mx' => ['name' => 'Regreso a clases', 'countries' => ['MX'], 'rule' => ['type' => 'nth', 'month' => 8, 'n' => -1, 'dow' => 1], 'days' => 14, 'lead' => 21,
            'ideas' => ['Listas de útiles por grado', 'Meses sin intereses en tecnología escolar', 'Uniformes y calzado escolar'],
            'audiences' => ['store', 'education'],
            'campaigns' => self::BACK_TO_SCHOOL],
        'regreso_clases_ve' => ['name' => 'Regreso a clases', 'countries' => ['VE'], 'rule' => ['type' => 'fixed', 'month' => 9, 'day' => 16], 'days' => 14, 'lead' => 21,
            'ideas' => ['Útiles y uniformes', 'Pagos en cuotas', 'Combos por grado'],
            'audiences' => ['store', 'education'],
            'campaigns' => self::BACK_TO_SCHOOL],
        'amor_amistad' => ['name' => 'Amor y Amistad', 'countries' => ['CO'], 'rule' => ['type' => 'nth', 'month' => 9, 'n' => 3, 'dow' => 6], 'days' => 1, 'lead' => 14,
            'ideas' => ['Regalos para el «amigo secreto» por precio', 'Dos por uno para compartir', 'Mensaje de cariño a los clientes fieles'],
            'audiences' => ['store', 'hospitality', 'health'],
            'campaigns' => [
                ['for' => 'store', 'template' => 'gal_promotion', 'copy' => ['subject' => 'Regalos para Amor y Amistad', 'headline' => 'Para tu amigo secreto', 'intro' => 'Ideas de regalo por precio para este Amor y Amistad. Pide a tiempo.']],
                ['for' => 'hospitality', 'template' => 'gal_promotion', 'copy' => ['subject' => 'Celebra Amor y Amistad con nosotros', 'headline' => 'Un plan para compartir', 'intro' => 'Reserva para celebrar Amor y Amistad con tu pareja o tus amigos.']],
                ['for' => 'health', 'template' => 'gal_promotion', 'copy' => ['subject' => 'Regala bienestar en Amor y Amistad', 'headline' => 'Un regalo que cuida', 'intro' => 'Un tratamiento o una sesión: el regalo de Amor y Amistad que de verdad se disfruta.']],
            ]],
        'halloween' => ['name' => 'Halloween', 'countries' => ['CO', 'MX'], 'rule' => ['type' => 'fixed', 'month' => 10, 'day' => 31], 'days' => 1, 'lead' => 14,
            'ideas' => ['Disfraces y decoración', 'Dulces y empaques temáticos'],
            'audiences' => ['store'],
            'campaigns' => [
                ['for' => 'store', 'template' => 'gal_promotion', 'copy' => ['subject' => 'Todo para Halloween', 'headline' => 'Prepárate para Halloween', 'intro' => 'Disfraces, decoración y dulces: todo para este 31 de octubre.']],
            ]],
        'buen_fin' => ['name' => 'El Buen Fin', 'countries' => ['MX'], 'rule' => ['type' => 'buen_fin'], 'days' => 4, 'lead' => 10,
            'ideas' => ['Acceso anticipado para clientes VIP', 'Meses sin intereses', 'Recordatorio el último día'],
            'audiences' => ['store', 'services'],
            'campaigns' => [
                ['for' => 'store', 'template' => 'gal_promotion', 'copy' => ['subject' => 'El Buen Fin en {{ tienda.nombre }}: acceso anticipado', 'headline' => 'Llega El Buen Fin', 'intro' => 'Te avisamos primero de nuestras ofertas de El Buen Fin y de los meses sin intereses.']],
                ['for' => 'services', 'template' => 'gal_promotion', 'copy' => ['subject' => 'Aprovecha El Buen Fin con {{ tienda.nombre }}', 'headline' => 'Precios especiales en El Buen Fin', 'intro' => 'Solo esos días: agenda o contrata con condiciones especiales.']],
            ]],
        'cierre_ano_b2b' => ['name' => 'Cierre de año y presupuesto', 'countries' => ['CO', 'MX', 'VE'], 'rule' => ['type' => 'fixed', 'month' => 11, 'day' => 15], 'days' => 30, 'lead' => 0,
            'ideas' => ['Cotizaciones antes del cierre del presupuesto', 'Planeación del próximo año', 'Precios del año siguiente'],
            'audiences' => ['b2b', 'professional'],
            'campaigns' => [
                ['for' => 'b2b', 'template' => 'gal_news', 'copy' => ['subject' => 'Planea el próximo año antes del cierre', 'headline' => 'Cierra el año con tu presupuesto listo', 'intro' => 'Si quieres incluirnos en el presupuesto del próximo año, pídenos la cotización antes del cierre.']],
                ['for' => 'professional', 'template' => 'gal_news', 'copy' => ['subject' => 'Cierra el año en orden', 'headline' => 'Prepara el próximo año con tiempo', 'intro' => 'Revisemos juntos lo pendiente antes del cierre y planeemos el próximo año.']],
            ]],
        'black_friday' => ['name' => 'Black Friday', 'countries' => ['CO', 'MX', 'VE'], 'rule' => ['type' => 'black_friday', 'offset' => 0], 'days' => 3, 'lead' => 10,
            'ideas' => ['Acceso anticipado para VIP (sin regalar descuento a los campeones)', 'Cuenta regresiva por WhatsApp a quien lo pidió', 'Excluir a quien compró en los últimos 7 días'],
            'audiences' => ['store', 'services'],
            'campaigns' => [
                ['for' => 'store', 'template' => 'gal_promotion', 'copy' => ['subject' => 'Black Friday en {{ tienda.nombre }}: tú te enteras primero', 'headline' => 'Llega el Black Friday', 'intro' => 'Prepárate: te contamos antes que a nadie nuestras ofertas de Black Friday.']],
                ['for' => 'services', 'template' => 'gal_promotion', 'copy' => ['subject' => 'Black Friday en {{ tienda.nombre }}', 'headline' => 'Condiciones especiales por Black Friday', 'intro' => 'Solo por estos días: agenda o contrata con un beneficio especial.']],
            ]],
        'cyberlunes' => ['name' => 'CyberLunes', 'countries' => ['CO'], 'rule' => ['type' => 'black_friday', 'offset' => 3], 'days' => 1, 'lead' => 5,
            'ideas' => ['Ofertas solo en línea por 24 horas', 'Envío gratis el día del evento'],
            'audiences' => ['store'],
            'campaigns' => [
                ['for' => 'store', 'template' => 'gal_promotion', 'copy' => ['subject' => 'CyberLunes: ofertas solo por 24 horas', 'headline' => 'Llega el CyberLunes', 'intro' => 'Un solo día de ofertas en línea. Guarda la fecha.']],
            ]],
        'vacaciones_fin_ano' => ['name' => 'Vacaciones de fin de año', 'countries' => ['CO', 'MX', 'VE'], 'rule' => ['type' => 'fixed', 'month' => 12, 'day' => 15], 'days' => 30, 'lead' => 21,
            'ideas' => ['Chequeo antes de vacaciones', 'Revisión del carro antes del viaje', 'Reservas de temporada alta'],
            'audiences' => self::VACATION_AUDIENCES,
            'campaigns' => self::VACATIONS],
        'prima_diciembre' => ['name' => 'Pago de prima (diciembre)', 'countries' => ['CO'], 'rule' => ['type' => 'fixed', 'month' => 12, 'day' => 20], 'days' => 1, 'lead' => 15,
            'ideas' => ['Regalos de Navidad con la prima', 'Productos de mayor valor en cuotas'],
            'audiences' => ['store', 'services'],
            'campaigns' => self::BONUS],
        'navidad' => ['name' => 'Navidad', 'countries' => ['CO', 'MX', 'VE'], 'rule' => ['type' => 'fixed', 'month' => 12, 'day' => 24], 'days' => 1, 'lead' => 24,
            'ideas' => ['Guía de regalos y fecha límite de envío', 'Tarjetas de regalo de última hora', 'Novenas (Colombia): ideas para compartir en familia'],
            'audiences' => ['store', 'services'],
            'campaigns' => [
                ['for' => 'store', 'template' => 'gal_promotion', 'segment' => 'rfm_hibernating', 'copy' => ['subject' => 'Regalos de Navidad: pide a tiempo', 'headline' => 'Que la Navidad llegue a tiempo', 'intro' => 'Elegimos los regalos favoritos de esta Navidad. Mira hasta cuándo puedes pedir para que lleguen.']],
                ['for' => 'services', 'template' => 'gal_seasonal', 'copy' => ['subject' => 'Feliz Navidad de parte de {{ tienda.nombre }}', 'headline' => '¡Feliz Navidad, {{ contacto.nombre | default: "hola" }}!', 'intro' => 'Gracias por confiar en nosotros este año. Te deseamos una Navidad en familia.']],
            ]],
    ];

    /** @var list<string> */
    private const VACATION_AUDIENCES = ['hospitality', 'automotive', 'health', 'education'];

    /** @var list<array{for: string, template: string, copy: array<string, string>}> */
    private const VACATIONS = [
        ['for' => 'hospitality', 'template' => 'gal_promotion', 'copy' => ['subject' => 'Reserva tus vacaciones', 'headline' => 'Las vacaciones están cerca', 'intro' => 'Las fechas de temporada se llenan primero. Reserva desde ya.']],
        ['for' => 'automotive', 'template' => 'gal_reminder', 'copy' => ['subject' => 'Revisa tu carro antes de viajar', 'headline' => 'Viaja tranquilo estas vacaciones', 'intro' => 'Agenda la revisión antes del viaje: frenos, llantas y aceite en un solo turno.']],
        ['for' => 'health', 'template' => 'gal_reminder', 'copy' => ['subject' => 'Tu chequeo antes de vacaciones', 'headline' => 'Antes de salir de vacaciones', 'intro' => 'Agenda tu control antes de viajar: hay horarios las próximas semanas.']],
        ['for' => 'education', 'template' => 'gal_promotion', 'copy' => ['subject' => 'Cursos de vacaciones', 'headline' => 'Aprovecha las vacaciones para aprender', 'intro' => 'Abrimos cursos cortos para las vacaciones. Los cupos son limitados.']],
    ];

    /** @var list<array{for: string, template: string, copy: array<string, string>}> */
    private const MOTHERS = [
        ['for' => 'store', 'template' => 'gal_promotion', 'copy' => ['subject' => 'Ideas de regalo para el Día de la Madre', 'headline' => 'Para mamá, lo mejor', 'intro' => 'Elegimos los regalos favoritos para el Día de la Madre. Pide a tiempo para que llegue.']],
        ['for' => 'health', 'template' => 'gal_promotion', 'copy' => ['subject' => 'Regálale a mamá un momento para ella', 'headline' => 'Consiente a mamá', 'intro' => 'Un tratamiento o una sesión para mamá: el regalo que de verdad disfruta.']],
        ['for' => 'hospitality', 'template' => 'gal_promotion', 'copy' => ['subject' => 'Celebra con mamá', 'headline' => 'Un plan para el Día de la Madre', 'intro' => 'Reserva para celebrar con mamá. Ese fin de semana se llena rápido.']],
    ];

    /** @var list<array{for: string, template: string, copy: array<string, string>}> */
    private const KIDS = [
        ['for' => 'store', 'template' => 'gal_promotion', 'copy' => ['subject' => 'Regalos para el Día del Niño', 'headline' => 'Para los más pequeños', 'intro' => 'Ideas de regalo por edad para el Día del Niño. Pide a tiempo.']],
        ['for' => 'hospitality', 'template' => 'gal_promotion', 'copy' => ['subject' => 'Un plan en familia para el Día del Niño', 'headline' => 'Celebren en familia', 'intro' => 'Reserva un plan para celebrar el Día del Niño con los tuyos.']],
        ['for' => 'education', 'template' => 'gal_event_invite', 'copy' => ['subject' => 'Clase abierta por el Día del Niño', 'headline' => 'Te invitamos con los niños', 'intro' => 'Preparamos una clase abierta para celebrar el Día del Niño. Inscríbelos.']],
    ];

    /** @var list<array{for: string, template: string, copy: array<string, string>}> */
    private const BONUS = [
        ['for' => 'store', 'template' => 'gal_promotion', 'copy' => ['subject' => 'Date un gusto con tu prima', 'headline' => 'Llegó la prima', 'intro' => 'Lo que tenías en la lista, ahora con facilidades de pago.']],
        ['for' => 'services', 'template' => 'gal_promotion', 'copy' => ['subject' => 'Invierte tu prima en ti', 'headline' => 'Llegó la prima', 'intro' => 'Es un buen momento para ese servicio que estabas aplazando. Agenda con nosotros.']],
    ];

    /** @var list<array{for: string, template: string, copy: array<string, string>}> */
    private const BACK_TO_SCHOOL = [
        ['for' => 'store', 'template' => 'gal_promotion', 'copy' => ['subject' => 'Regreso a clases: todo listo en un solo pedido', 'headline' => 'Que no falte nada para el regreso a clases', 'intro' => 'Arma la lista escolar con nosotros y recíbela a tiempo.']],
        ['for' => 'education', 'template' => 'gal_reminder', 'copy' => ['subject' => 'Últimos cupos antes del regreso a clases', 'headline' => 'Asegura tu cupo antes de empezar', 'intro' => 'Se acerca el regreso a clases y los grupos se están llenando. Reserva tu cupo a tiempo.']],
    ];

    /**
     * @return array<string, array{name: string, countries: list<string>, rule: array<string, int|string>, days: int, lead: int, notice?: int, ideas: list<string>,
     *     audiences: list<string>, campaigns: list<array<string, mixed>>}>
     */
    private static function events(): array
    {
        return self::EVENTS;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::events());
    }

    public static function name(string $key): ?string
    {
        return isset(self::events()[$key]) ? self::t(self::events()[$key]['name']) : null;
    }

    /** @return list<string> países del momento */
    public static function countriesOf(string $key): array
    {
        return self::events()[$key]['countries'] ?? [];
    }

    /**
     * Momentos del año para el país, ordenados por fecha, con las excepciones de la plataforma aplicadas (los que ese
     * año no se hacen no salen).
     *
     * @return list<array{key: string, name: string, date: string, ends: string, starts_communicating: string, notice_from: string, ideas: list<string>,
     *     audiences: list<string>, campaigns: list<array<string, mixed>>, rule_date: string, overridden: bool}>
     */
    public static function forYear(int $year, string $country): array
    {
        $country = strtoupper($country);
        $overrides = self::overrides($country, $year);
        $out = [];
        foreach (self::events() as $key => $event) {
            if (! in_array($country, $event['countries'], true)) {
                continue;
            }
            $override = $overrides[$key] ?? null;
            if ($override !== null && $override['disabled']) {
                continue;
            }
            $rule = self::resolve($event['rule'], $year);
            $date = $override['starts_on'] ?? $rule;
            $ends = $override['ends_on'] ?? $date->addDays(max(0, $event['days'] - 1));
            $send = $date->subDays($event['lead']);
            $out[] = [
                'key' => $key,
                'name' => self::t($event['name']),
                'date' => $date->toDateString(),
                'ends' => $ends->toDateString(),
                'starts_communicating' => $send->toDateString(),
                'notice_from' => $send->subDays($event['notice'] ?? self::NOTICE_DAYS)->toDateString(),
                'ideas' => array_map(fn (string $i) => self::t($i), $event['ideas']),
                'audiences' => $event['audiences'],
                'campaigns' => $event['campaigns'],
                'rule_date' => $rule->toDateString(),
                'overridden' => $override !== null,
            ];
        }
        usort($out, fn (array $a, array $b) => strcmp($a['date'], $b['date']));

        return $out;
    }

    /**
     * Próximos momentos para un negocio: los de su país que aplican a su tipo de negocio y vertical, que todavía no
     * terminan y empiezan dentro de `$days` días, cada uno con la campaña sugerida para él.
     *
     * @return list<array<string, mixed>>
     */
    public static function upcoming(string $country, string $businessType, ?string $vertical, CarbonImmutable $today, int $days = 120): array
    {
        $country = in_array(strtoupper($country), self::COUNTRIES, true) ? strtoupper($country) : 'CO';
        $limit = $today->addDays($days)->toDateString();
        $out = [];
        foreach ([...self::forYear($today->year, $country), ...self::forYear($today->year + 1, $country)] as $event) {
            if ($event['ends'] < $today->toDateString() || $event['date'] > $limit || ! self::appliesTo($event['audiences'], $businessType, $vertical)) {
                continue;
            }
            $campaign = self::campaignFor($event['campaigns'], $businessType, $vertical);
            if ($campaign === null) {
                continue;
            }
            $out[] = ['year' => (int) substr($event['date'], 0, 4), 'campaign' => $campaign] + $event;
        }

        return $out;
    }

    /** @param list<string> $audiences */
    public static function appliesTo(array $audiences, string $businessType, ?string $vertical): bool
    {
        $store = in_array($businessType, ['store', 'both'], true);
        $services = in_array($businessType, ['services', 'both'], true);

        return ($store && in_array('store', $audiences, true))
            || ($services && (in_array('services', $audiences, true) || ($vertical !== null && $vertical !== 'store' && in_array($vertical, $audiences, true))));
    }

    /**
     * La campaña sugerida más específica: la de su vertical, la de servicios o la de tienda.
     *
     * @param  list<array<string, mixed>>  $campaigns
     * @return array{for: string, template: string, channel: string, segment: string|null, copy: array<string, string>}|null
     */
    public static function campaignFor(array $campaigns, string $businessType, ?string $vertical): ?array
    {
        $order = [];
        if (in_array($businessType, ['services', 'both'], true)) {
            if ($vertical !== null && $vertical !== 'store') {
                $order[] = $vertical;
            }
            $order[] = 'services';
        }
        if (in_array($businessType, ['store', 'both'], true)) {
            $order[] = 'store';
        }
        foreach ($order as $for) {
            foreach ($campaigns as $c) {
                if (($c['for'] ?? null) === $for) {
                    return [
                        'for' => $for,
                        'template' => (string) $c['template'],
                        'channel' => (string) ($c['channel'] ?? 'email'),
                        'segment' => isset($c['segment']) ? (string) $c['segment'] : null,
                        'copy' => array_map(fn ($v) => self::t((string) $v), (array) ($c['copy'] ?? [])),
                    ];
                }
            }
        }

        return null;
    }

    /** @param array<string, int|string> $rule */
    public static function resolve(array $rule, int $year): CarbonImmutable
    {
        return match ($rule['type']) {
            'fixed' => self::date($year, (int) $rule['month'], (int) $rule['day']),
            'nth' => self::nth($year, (int) $rule['month'], (int) $rule['n'], (int) $rule['dow']),
            // Viernes después del cuarto jueves de noviembre (el de Estados Unidos, que se adoptó en LATAM).
            'black_friday' => self::nth($year, 11, 4, 4)->addDays(1 + (int) ($rule['offset'] ?? 0)),
            // De viernes a lunes, terminando el tercer lunes de noviembre (Día de la Revolución trasladado).
            'buen_fin' => self::nth($year, 11, 3, 1)->subDays(3),
            // Último lunes de mayo, nueve días.
            'hot_sale' => self::nth($year, 5, -1, 1),
            // Relativa al domingo de Pascua (Semana Santa empieza el Domingo de Ramos, 7 días antes).
            'easter' => Holidays::easter($year)->addDays((int) ($rule['offset'] ?? 0)),
            default => self::date($year, 1, 1),
        };
    }

    /** La fecha de la regla de un momento (sin excepciones), o null si el momento no existe. */
    public static function ruleDate(string $key, int $year): ?CarbonImmutable
    {
        return isset(self::events()[$key]) ? self::resolve(self::events()[$key]['rule'], $year) : null;
    }

    /**
     * Excepciones de la plataforma para un país y un año.
     *
     * @return array<string, array{starts_on: CarbonImmutable|null, ends_on: CarbonImmutable|null, disabled: bool, note: string|null}>
     */
    public static function overrides(string $country, int $year): array
    {
        try {
            $rows = DB::table('commercial_calendar_overrides')->where('country', strtoupper($country))->where('year', $year)
                ->get(['event_key', 'starts_on', 'ends_on', 'disabled', 'note']);
        } catch (Throwable) {
            return []; // sin base de datos (pruebas unitarias): solo las reglas
        }
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->event_key] = [
                'starts_on' => $row->starts_on !== null ? CarbonImmutable::parse((string) $row->starts_on)->startOfDay() : null,
                'ends_on' => $row->ends_on !== null ? CarbonImmutable::parse((string) $row->ends_on)->startOfDay() : null,
                'disabled' => (bool) $row->disabled,
                'note' => $row->note !== null ? (string) $row->note : null,
            ];
        }

        return $out;
    }

    /** n-ésimo día de la semana ISO del mes (n = -1: el último). */
    private static function nth(int $year, int $month, int $n, int $dow): CarbonImmutable
    {
        if ($n < 0) {
            $day = self::date($year, $month, 1)->endOfMonth()->startOfDay();
            while ($day->dayOfWeekIso !== $dow) {
                $day = $day->subDay();
            }

            return $day;
        }
        $day = self::date($year, $month, 1);
        while ($day->dayOfWeekIso !== $dow) {
            $day = $day->addDay();
        }

        return $day->addWeeks($n - 1);
    }

    private static function date(int $year, int $month, int $day): CarbonImmutable
    {
        return new CarbonImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
    }

    private static function t(string $text): string
    {
        $translated = __($text);

        return is_string($translated) ? $translated : $text;
    }
}
