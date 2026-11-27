<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Ai\Features\Assistant;

use App\Analytics\Semantic\Catalog;
use App\Domain\Intelligence\Ai\Support\NumberValidator;
use App\Domain\Strategies\StrategyRegistry;
use Illuminate\Support\Facades\App;

/**
 * Lo estable que «Pregúntale a la IA» sabe del producto (inteligencia.md §6.3): su papel y sus reglas, la base de
 * conocimiento versionada (`resources/assistant/knowledge.md`), las estrategias con su nombre y su promesa, las
 * pantallas a las que puede llevar (`AssistantPages`) y el catálogo de métricas de `run_metric_query`. Todo va en el
 * `system`, que no cambia entre preguntas (se cachea); lo de la cuenta y la pantalla va en el mensaje.
 *
 * La respuesta es JSON (`SCHEMA`, salida estructurada): `answer` siempre con texto, `steps` con enlaces a pantallas
 * del catálogo y `action` para un borrador con el copiloto. Así el texto nunca se pierde entre llamadas a la
 * herramienta (en los modelos 5.5 lo que se escribe entre herramientas no llega como texto) ni queda solo un botón.
 */
final class AssistantKnowledge
{
    /** @var array<string, string> sistema por idioma */
    private static array $system = [];

    /** @var list<float>|null */
    private static ?array $numbers = null;

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        $pages = [...AssistantPages::keys(), 'none'];

        return [
            'type' => 'object',
            'properties' => [
                'answer' => ['type' => 'string', 'description' => 'La respuesta para la persona, en 1 a 5 frases cortas. Nunca vacía.'],
                'steps' => [
                    'type' => 'array',
                    'description' => 'De 0 a 3 pasos concretos, cada uno con la pantalla donde se hace. Vacío si la pregunta no pide pasos.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'text' => ['type' => 'string', 'description' => 'El paso en una frase, empezando por un verbo.'],
                            'page' => ['type' => 'string', 'enum' => $pages, 'description' => 'La pantalla del catálogo donde se hace, o none.'],
                            'ref' => ['type' => 'string', 'description' => 'Solo con page = strategy: la clave de la estrategia. Si no, vacío.'],
                        ],
                        'required' => ['text', 'page', 'ref'],
                        'additionalProperties' => false,
                    ],
                ],
                'action' => [
                    'type' => 'object',
                    'description' => 'Un borrador con el copiloto, solo si la persona quiere escribirle a sus clientes. Si no, kind none.',
                    'properties' => [
                        'kind' => ['type' => 'string', 'enum' => ['none', 'campaign', 'flow']],
                        'prompt' => ['type' => 'string', 'description' => 'El pedido al copiloto en una frase. Vacío con none.'],
                    ],
                    'required' => ['kind', 'prompt'],
                    'additionalProperties' => false,
                ],
            ],
            'required' => ['answer', 'steps', 'action'],
            'additionalProperties' => false,
        ];
    }

    public static function system(): string
    {
        $locale = App::getLocale();

        return self::$system[$locale] ??= self::build();
    }

    /**
     * Las cifras de la base de conocimiento (topes, horas, meses): el asistente las puede decir sin que vengan de los
     * datos del negocio.
     *
     * @return list<float>
     */
    public static function numbers(): array
    {
        if (self::$numbers === null) {
            preg_match_all('/\d+(?:[.,]\d+)*/', self::knowledge(), $m);
            self::$numbers = array_values(array_unique(array_map(fn (string $n) => NumberValidator::parse($n)[0], $m[0])));
        }

        return self::$numbers;
    }

    /** Para las pruebas: vuelve a leer los archivos. */
    public static function flush(): void
    {
        self::$system = [];
        self::$numbers = null;
    }

    private static function knowledge(): string
    {
        $raw = (string) file_get_contents(resource_path('assistant/knowledge.md'));

        return trim((string) preg_replace('/<!--.*?-->/s', '', $raw));
    }

    private static function build(): string
    {
        $strategies = [];
        foreach (StrategyRegistry::all() as $s) {
            $audience = match ($s->audience) {
                'store' => 'tienda',
                'services' => 'servicios',
                default => 'tienda y servicios',
            };
            $strategies[] = "- {$s->key}: ".__('strategies.items.'.$s->key.'.name').' — '.__('strategies.items.'.$s->key.'.promise')." ({$audience})";
        }
        $pages = [];
        foreach (AssistantPages::all() as $key => $path) {
            $pages[] = "- {$key}: /{$path}";
        }
        $metrics = [];
        foreach (Catalog::METRICS as $key => $m) {
            $metrics[] = "- {$key} ({$m['label']}, {$m['unit']})";
        }
        $dims = [];
        foreach (Catalog::DIMENSIONS as $key => $d) {
            $dims[] = "- {$key}: {$d['label']}";
        }

        return implode("\n\n", [
            'Eres el asistente de Imagina Growth dentro del panel: ayudas a la persona que lo usa a entender y usar la plataforma y a leer los números de su negocio. '
            .'Hablas en español neutro, en segunda persona, cercano, corto y sin jerga técnica. Respondes lo que preguntó; si la pregunta sigue a otra («ok pero…», «¿y eso?»), usa la conversación anterior para entenderla.',
            "Cómo respondes:\n"
            ."- Tu respuesta final es el JSON pedido: «answer» siempre trae texto útil (nunca lo dejes vacío ni digas solo «usa el botón»). Si conviene, «steps» trae hasta 3 pasos concretos con la pantalla donde se hace cada uno; el panel los muestra como enlaces.\n"
            ."- Para «¿cómo empiezo?», «¿cómo le saco provecho?» o «¿qué hago ahora?», usa el «Estado de la cuenta» y los «Próximos pasos sugeridos» del mensaje: di qué falta y en qué orden, según cómo está la cuenta (no repitas lo que ya está conectado o prendido).\n"
            ."- Para «¿cómo conecto…?» o «¿cómo funciona…?», explica con la base de conocimiento y el estado de la cuenta, y lleva a la pantalla exacta.\n"
            ."- Para cifras del negocio (ventas, pedidos, clientes, visitas, canales, productos), usa la herramienta run_metric_query y responde con lo que devuelva. Si no está en las métricas, dilo sin inventar.\n"
            ."- Si «puede_configurar» es false, la persona no puede conectar ni prender cosas: dile que se lo pida al dueño o a un administrador.\n"
            ."- Nunca inventes funciones, pantallas, precios ni cifras: cada número que escribas debe venir de los hechos, de la herramienta o de la base de conocimiento. Nunca digas que ya creaste, enviaste, conectaste o prendiste algo.\n"
            .'- «action» solo para proponer un borrador de campaña o de automatización con el copiloto cuando la persona quiera escribirle a sus clientes; la persona lo revisa antes de enviarlo.',
            "Base de conocimiento del producto (los nombres entre corchetes son claves de pantallas):\n".self::knowledge(),
            "Estrategias (clave: nombre — para qué sirve):\n".implode("\n", $strategies),
            "Pantallas a las que puedes llevar (clave: ruta). En «steps» usa solo estas claves; con «strategy», «ref» es la clave de una estrategia:\n".implode("\n", $pages),
            "Métricas de run_metric_query:\n".implode("\n", $metrics)."\n\nDimensiones (máximo 2):\n".implode("\n", $dims)."\n"
            .'Dinero con $ y puntos de miles ($1.234.567); porcentajes con coma decimal; la conversión viene como proporción (0,0183 = 1,83 %). No hagas tablas: el panel muestra la tabla y el gráfico de la consulta.',
        ]);
    }
}
