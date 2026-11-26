<?php

declare(strict_types=1);

namespace App\Domain\Services\Verticals;

use App\Domain\Onboarding\Models\WorkspaceOnboarding;
use App\Domain\Onboarding\Support\Verticals;
use App\Domain\Services\Leads\Support\LeadDefaults;
use App\Domain\Strategies\StrategyRegistry;
use App\Domain\Strategies\Support\StrategyContext;
use App\Domain\Workspaces\Models\Workspace;

/**
 * Perfiles de vertical (docs/diseno/rediseno-v2.md §11.1, modulos.md M18 «Verticales»). Envuelve las verticales del
 * asistente (`config/igr-verticals.php` y `Onboarding\Support\Verticals`: las claves, el tipo de negocio, las
 * industrias y el nombre en `lang/es/onboarding.php`) y les agrega lo que necesitan los servicios: la descripción,
 * cómo se llama a la persona (cliente, paciente, estudiante…), el embudo de leads por defecto, lo que más le importa
 * al negocio y las plantillas sugeridas de la galería. Las estrategias y los flujos recomendados salen del catálogo de
 * Estrategias (`StrategyRegistry::rankedFor`), no se repiten aquí.
 *
 * Las etapas reutilizan las del pipeline simple (`new`, `contacted`, `qualified`, `proposal`, `won`, `lost`) y agregan
 * las propias de cada vertical; la de ganado es siempre `won` y la de perdido `lost` (el dinero generado y el Inicio
 * cuentan los leads con `stage = won`). Entrar a una etapa de QUOTE_STAGES emite `quote.sent` (v8). Una vertical nueva
 * en la configuración sin perfil aquí usa el de `other`.
 *
 * Los textos se guardan en español y se traducen al leerlos (`__()`).
 */
final class VerticalCatalog
{
    public const BUSINESS_TYPES = WorkspaceOnboarding::BUSINESS_TYPES;

    /** Etapas en las que el negocio ya envió una cotización, una propuesta o una oferta (emiten `quote.sent`). */
    public const QUOTE_STAGES = LeadDefaults::QUOTE_STAGES;

    /**
     * @var array<string, array{description: string, terms: array{client: string, clients: string, prospect: string, prospects: string},
     *     pipeline: array{name: string, stages: list<array{key: string, label: string, kind: string}>}|null,
     *     priorities: list<string>, templates: list<string>}>
     */
    private const PROFILES = [
        'store' => [
            'description' => 'Vendes productos en tu tienda WooCommerce: recupera carritos, haz que vuelvan a comprar y junta reseñas.',
            'terms' => ['client' => 'cliente', 'clients' => 'clientes', 'prospect' => 'interesado', 'prospects' => 'interesados'],
            'pipeline' => null,
            'priorities' => ['Recuperar carritos', 'Que vuelvan a comprar', 'Reseñas con foto'],
            'templates' => ['gal_welcome', 'wa_gal_welcome', 'gal_promotion', 'wa_gal_promotion', 'gal_news', 'wa_gal_news', 'gal_seasonal', 'wa_gal_seasonal', 'gal_review', 'wa_gal_review', 'gal_survey'],
        ],
        'professional' => [
            'description' => 'Abogados, contadores, consultores y agencias: responde rápido, cotiza y haz seguimiento sin olvidar a nadie.',
            'terms' => ['client' => 'cliente', 'clients' => 'clientes', 'prospect' => 'prospecto', 'prospects' => 'prospectos'],
            'pipeline' => ['name' => 'Clientes potenciales', 'stages' => [
                ['key' => 'new', 'label' => 'Nuevo', 'kind' => 'open'],
                ['key' => 'contacted', 'label' => 'Contactado', 'kind' => 'open'],
                ['key' => 'meeting', 'label' => 'Reunión', 'kind' => 'open'],
                ['key' => 'proposal', 'label' => 'Propuesta', 'kind' => 'open'],
                ['key' => 'won', 'label' => 'Ganado', 'kind' => 'won'],
                ['key' => 'lost', 'label' => 'Perdido', 'kind' => 'lost'],
            ]],
            'priorities' => ['Responder rápido', 'Cotizar', 'Hacer seguimiento'],
            'templates' => ['gal_welcome', 'wa_gal_welcome', 'gal_news', 'gal_event_invite', 'wa_gal_event_invite', 'gal_seasonal', 'gal_survey', 'wa_gal_review'],
        ],
        'health' => [
            'description' => 'Clínicas, odontología, estética, spa y gimnasios: agenda citas, baja las inasistencias y trae a tus pacientes a sus controles.',
            'terms' => ['client' => 'paciente', 'clients' => 'pacientes', 'prospect' => 'interesado', 'prospects' => 'interesados'],
            'pipeline' => ['name' => 'Pacientes', 'stages' => [
                ['key' => 'new', 'label' => 'Nuevo', 'kind' => 'open'],
                ['key' => 'contacted', 'label' => 'Contactado', 'kind' => 'open'],
                ['key' => 'appointment', 'label' => 'Cita agendada', 'kind' => 'open'],
                ['key' => 'attended', 'label' => 'Asistió', 'kind' => 'open'],
                ['key' => 'won', 'label' => 'Paciente', 'kind' => 'won'],
                ['key' => 'lost', 'label' => 'No continuó', 'kind' => 'lost'],
            ]],
            'priorities' => ['Citas agendadas', 'Menos inasistencias', 'Controles periódicos', 'Reseñas'],
            'templates' => ['gal_welcome', 'wa_gal_welcome', 'gal_reminder', 'wa_gal_reminder', 'gal_promotion', 'wa_gal_promotion', 'gal_seasonal', 'wa_gal_seasonal', 'gal_review', 'wa_gal_review', 'gal_survey'],
        ],
        'education' => [
            'description' => 'Academias, cursos, colegios e idiomas: convierte interesados en inscritos antes de la fecha límite.',
            'terms' => ['client' => 'estudiante', 'clients' => 'estudiantes', 'prospect' => 'interesado', 'prospects' => 'interesados'],
            'pipeline' => ['name' => 'Inscripciones', 'stages' => [
                ['key' => 'new', 'label' => 'Interesado', 'kind' => 'open'],
                ['key' => 'info_sent', 'label' => 'Información enviada', 'kind' => 'open'],
                ['key' => 'trial', 'label' => 'Visita o clase de prueba', 'kind' => 'open'],
                ['key' => 'won', 'label' => 'Inscrito', 'kind' => 'won'],
                ['key' => 'lost', 'label' => 'No se inscribió', 'kind' => 'lost'],
            ]],
            'priorities' => ['Inscripciones', 'Fechas límite', 'Recordatorios'],
            'templates' => ['gal_welcome', 'wa_gal_welcome', 'gal_event_invite', 'wa_gal_event_invite', 'gal_news', 'wa_gal_news', 'gal_reminder', 'gal_seasonal', 'gal_survey'],
        ],
        'real_estate' => [
            'description' => 'Venta y arriendo: muestra propiedades similares, agenda visitas y haz un seguimiento largo sin perder a nadie.',
            'terms' => ['client' => 'cliente', 'clients' => 'clientes', 'prospect' => 'prospecto', 'prospects' => 'prospectos'],
            'pipeline' => ['name' => 'Clientes potenciales', 'stages' => [
                ['key' => 'new', 'label' => 'Nuevo', 'kind' => 'open'],
                ['key' => 'contacted', 'label' => 'Contactado', 'kind' => 'open'],
                ['key' => 'visit', 'label' => 'Visita agendada', 'kind' => 'open'],
                ['key' => 'offer', 'label' => 'Oferta', 'kind' => 'open'],
                ['key' => 'won', 'label' => 'Cerrado', 'kind' => 'won'],
                ['key' => 'lost', 'label' => 'Perdido', 'kind' => 'lost'],
            ]],
            'priorities' => ['Interés por propiedad', 'Visitas', 'Seguimiento largo'],
            'templates' => ['gal_welcome', 'wa_gal_welcome', 'gal_news', 'wa_gal_news', 'gal_event_invite', 'gal_seasonal', 'gal_review'],
        ],
        'hospitality' => [
            'description' => 'Hoteles, tours y restaurantes: responde las consultas, convierte cotizaciones en reservas y junta reseñas de Google.',
            'terms' => ['client' => 'cliente', 'clients' => 'clientes', 'prospect' => 'interesado', 'prospects' => 'interesados'],
            'pipeline' => ['name' => 'Reservas', 'stages' => [
                ['key' => 'new', 'label' => 'Consulta', 'kind' => 'open'],
                ['key' => 'quoted', 'label' => 'Cotizado', 'kind' => 'open'],
                ['key' => 'won', 'label' => 'Reservado', 'kind' => 'won'],
                ['key' => 'lost', 'label' => 'No reservó', 'kind' => 'lost'],
            ]],
            'priorities' => ['Reservas', 'Reseñas de Google', 'Temporadas'],
            'templates' => ['gal_welcome', 'wa_gal_welcome', 'gal_promotion', 'wa_gal_promotion', 'gal_seasonal', 'wa_gal_seasonal', 'gal_review', 'wa_gal_review'],
        ],
        'automotive' => [
            'description' => 'Talleres y concesionarios: cotiza, agenda la cita de taller o la prueba de manejo y recuerda los mantenimientos.',
            'terms' => ['client' => 'cliente', 'clients' => 'clientes', 'prospect' => 'prospecto', 'prospects' => 'prospectos'],
            'pipeline' => ['name' => 'Clientes potenciales', 'stages' => [
                ['key' => 'new', 'label' => 'Nuevo', 'kind' => 'open'],
                ['key' => 'quoted', 'label' => 'Cotizado', 'kind' => 'open'],
                ['key' => 'appointment', 'label' => 'Cita de taller o prueba', 'kind' => 'open'],
                ['key' => 'won', 'label' => 'Cerrado', 'kind' => 'won'],
                ['key' => 'lost', 'label' => 'Perdido', 'kind' => 'lost'],
            ]],
            'priorities' => ['Mantenimientos periódicos', 'Cotizaciones'],
            'templates' => ['gal_welcome', 'wa_gal_welcome', 'gal_reminder', 'wa_gal_reminder', 'gal_promotion', 'gal_seasonal', 'gal_review', 'wa_gal_review'],
        ],
        'b2b' => [
            'description' => 'Distribuidores, manufactura y software: ciclos largos, cotizaciones y nutrición por correo hasta cerrar.',
            'terms' => ['client' => 'cliente', 'clients' => 'clientes', 'prospect' => 'prospecto', 'prospects' => 'prospectos'],
            'pipeline' => ['name' => 'Oportunidades', 'stages' => [
                ['key' => 'new', 'label' => 'Nuevo', 'kind' => 'open'],
                ['key' => 'qualified', 'label' => 'Calificado', 'kind' => 'open'],
                ['key' => 'meeting', 'label' => 'Reunión', 'kind' => 'open'],
                ['key' => 'proposal', 'label' => 'Propuesta', 'kind' => 'open'],
                ['key' => 'negotiation', 'label' => 'Negociación', 'kind' => 'open'],
                ['key' => 'won', 'label' => 'Ganado', 'kind' => 'won'],
                ['key' => 'lost', 'label' => 'Perdido', 'kind' => 'lost'],
            ]],
            'priorities' => ['Ciclos largos', 'Cotizaciones', 'Nutrición por correo'],
            'templates' => ['gal_welcome', 'gal_news', 'gal_event_invite', 'wa_gal_event_invite', 'gal_seasonal', 'gal_survey'],
        ],
        'other' => [
            'description' => 'Cualquier negocio que recibe consultas: responde, haz seguimiento y convierte más contactos en clientes.',
            'terms' => ['client' => 'cliente', 'clients' => 'clientes', 'prospect' => 'prospecto', 'prospects' => 'prospectos'],
            'pipeline' => ['name' => 'Clientes potenciales', 'stages' => [
                ['key' => 'new', 'label' => 'Nuevo', 'kind' => 'open'],
                ['key' => 'contacted', 'label' => 'Contactado', 'kind' => 'open'],
                ['key' => 'won', 'label' => 'Ganado', 'kind' => 'won'],
                ['key' => 'lost', 'label' => 'Perdido', 'kind' => 'lost'],
            ]],
            'priorities' => ['Leads', 'Seguimiento'],
            'templates' => ['gal_welcome', 'wa_gal_welcome', 'gal_news', 'gal_promotion', 'gal_seasonal', 'gal_review', 'gal_survey'],
        ],
    ];

    /** @return list<string> las claves del asistente (config/igr-verticals.php) */
    public static function keys(): array
    {
        return Verticals::keys();
    }

    public static function exists(?string $key): bool
    {
        return Verticals::valid($key);
    }

    /**
     * Todos los perfiles, traducidos, en el orden de la configuración.
     *
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        return array_values(array_map(fn (string $key) => (array) self::find($key), self::keys()));
    }

    /**
     * Perfil traducido para la SPA y las demás capas.
     *
     * @return array{key: string, name: string, examples: string, description: string, business_type: string,
     *     terms: array{client: string, clients: string, prospect: string, prospects: string},
     *     pipeline: array{name: string, stages: list<array{key: string, label: string, kind: string}>}|null,
     *     priorities: list<string>, strategies: list<string>, flows: list<string>, templates: list<string>, industry: string|null}|null
     */
    public static function find(?string $key): ?array
    {
        if ($key === null || ! self::exists($key)) {
            return null;
        }
        $p = self::profile($key);
        $strategies = StrategyRegistry::rankedFor($key);
        $flows = [];
        foreach ($strategies as $strategy) {
            foreach (StrategyRegistry::find($strategy)?->flowKeys() ?? [] as $flow) {
                $flows[$flow] = true;
            }
        }

        return [
            'key' => $key,
            'name' => self::t("onboarding.verticals.{$key}.name"),
            'examples' => self::t("onboarding.verticals.{$key}.examples"),
            'description' => self::t($p['description']),
            'business_type' => Verticals::businessType($key),
            'terms' => array_map(fn (string $t) => self::t($t), $p['terms']),
            'pipeline' => $p['pipeline'] === null ? null : ['name' => self::t($p['pipeline']['name']), 'stages' => self::translate($p['pipeline']['stages'])],
            'priorities' => array_map(fn (string $e) => self::t($e), $p['priorities']),
            'strategies' => $strategies,
            'flows' => array_values(array_map('strval', array_keys($flows))),
            'templates' => $p['templates'],
            'industry' => self::industry($key),
        ];
    }

    /**
     * Etapas del embudo por defecto del vertical (traducidas), o null si no tiene embudo (tienda).
     *
     * @return list<array{key: string, label: string, kind: string}>|null
     */
    public static function stages(?string $key): ?array
    {
        $pipeline = $key !== null && self::exists($key) ? self::profile($key)['pipeline'] : null;

        return $pipeline === null ? null : self::translate($pipeline['stages']);
    }

    public static function pipelineName(?string $key): ?string
    {
        $pipeline = $key !== null && self::exists($key) ? self::profile($key)['pipeline'] : null;

        return $pipeline === null ? null : self::t($pipeline['name']);
    }

    /**
     * Embudos de fábrica (el simple y los de cada vertical) en su versión en español y traducida: un pipeline que
     * coincide con uno de estos no lo personalizó nadie.
     *
     * @return list<array{name: list<string>, stages: list<list<array{key: string, label: string, kind: string}>>}>
     */
    public static function presets(): array
    {
        $base = array_map(fn (array $s) => ['key' => $s['key'], 'label' => $s['label'], 'kind' => $s['kind']], LeadDefaults::STAGES);
        $out = [['name' => ['Ventas', self::t('Ventas')], 'stages' => [$base, self::translate($base)]]];
        foreach (self::PROFILES as $profile) {
            if ($profile['pipeline'] !== null) {
                $out[] = ['name' => [$profile['pipeline']['name'], self::t($profile['pipeline']['name'])], 'stages' => [$profile['pipeline']['stages'], self::translate($profile['pipeline']['stages'])]];
            }
        }

        return $out;
    }

    /** La industria de la copia de las plantillas: la única de la vertical en la configuración (la tienda tiene varias). */
    public static function industry(?string $key): ?string
    {
        $industries = $key !== null && self::exists($key) ? array_values((array) config("igr-verticals.verticals.{$key}.industries", [])) : [];

        return count($industries) === 1 && is_string($industries[0]) ? $industries[0] : null;
    }

    /**
     * Tipo de negocio y vertical del espacio de trabajo, con las mismas reglas que Estrategias e Inicio
     * (StrategyContext): la vertical guardada (`workspaces.vertical`) o la que sugieren la industria o los datos, y el
     * tipo elegido en el asistente o el que implica la vertical. `saved` dice si la vertical la eligió la persona.
     * Corre con el espacio de trabajo activo.
     *
     * @return array{business_type: string, vertical: string, saved: bool}
     */
    public static function forWorkspace(Workspace $workspace): array
    {
        $ctx = StrategyContext::for($workspace);

        return ['business_type' => $ctx->businessType(), 'vertical' => $ctx->vertical(), 'saved' => self::saved($workspace) !== null];
    }

    /** La vertical que eligió la persona (`workspaces.vertical`), o null. */
    public static function saved(Workspace $workspace): ?string
    {
        $vertical = $workspace->getAttributes()['vertical'] ?? null;

        return is_string($vertical) && self::exists($vertical) ? $vertical : null;
    }

    /** ¿El espacio de trabajo vende servicios (servicios o tienda + servicios)? */
    public static function sellsServices(Workspace $workspace): bool
    {
        return self::forWorkspace($workspace)['business_type'] !== 'store';
    }

    /**
     * @return array{description: string, terms: array{client: string, clients: string, prospect: string, prospects: string},
     *     pipeline: array{name: string, stages: list<array{key: string, label: string, kind: string}>}|null,
     *     priorities: list<string>, templates: list<string>}
     */
    private static function profile(string $key): array
    {
        return self::PROFILES[$key] ?? self::PROFILES['other'];
    }

    /**
     * @param  list<array{key: string, label: string, kind: string}>  $stages
     * @return list<array{key: string, label: string, kind: string}>
     */
    private static function translate(array $stages): array
    {
        return array_map(fn (array $s) => ['key' => $s['key'], 'label' => self::t($s['label']), 'kind' => $s['kind']], $stages);
    }

    private static function t(string $text): string
    {
        $translated = __($text);

        return is_string($translated) ? $translated : $text;
    }
}
