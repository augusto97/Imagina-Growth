<?php

declare(strict_types=1);

namespace App\Domain\Billing\Platform;

/**
 * Un punto del diagnóstico de la instalación: qué se revisó, cómo está y, si falta algo, qué hacer.
 * `status`: ok | info | warning | error. `items`: detalle opcional (sitios, cuentas), sin datos personales.
 * `link`: dónde se arregla en Plataforma, como consulta de la pantalla (`pestana=ajustes&seccion=mail`), o null.
 */
final readonly class DoctorCheck
{
    public const OK = 'ok';

    public const INFO = 'info';

    public const WARNING = 'warning';

    public const ERROR = 'error';

    /** @param list<string> $items */
    public function __construct(
        public string $key,
        public string $group,
        public string $status,
        public string $title,
        public string $detail = '',
        public array $items = [],
        public ?string $link = null,
    ) {}

    /** Consulta de Plataforma que abre una sección de Ajustes. */
    public static function settings(string $section): string
    {
        return 'pestana=ajustes&seccion='.$section;
    }

    /** @return array{key: string, group: string, status: string, title: string, detail: string, items: list<string>, link: string|null} */
    public function toArray(): array
    {
        return ['key' => $this->key, 'group' => $this->group, 'status' => $this->status, 'title' => $this->title, 'detail' => $this->detail, 'items' => $this->items, 'link' => $this->link];
    }
}
