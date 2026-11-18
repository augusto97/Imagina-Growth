<?php

declare(strict_types=1);

namespace App\Analytics\Semantic;

use App\Domain\Sites\Models\Site;
use Illuminate\Http\Request;

/** Filtros comunes de los tableros (M05): sitio, canal, dispositivo y ciudad. */
final class Filters
{
    public function __construct(
        public readonly ?Site $site = null,
        public readonly ?string $channel = null,
        public readonly ?string $device = null,
        public readonly ?string $city = null,
    ) {}

    /** Debe llamarse con el espacio de trabajo activo (el sitio se busca con el scope de tenancy). */
    public static function fromRequest(Request $request): self
    {
        $str = fn (string $key) => is_string($request->query($key)) && $request->query($key) !== '' ? mb_substr((string) $request->query($key), 0, 120) : null;
        $siteId = $str('site');

        return new self(
            site: $siteId !== null ? Site::findByPublicId($siteId) : null,
            channel: $str('channel'),
            device: in_array($str('device'), ['mobile', 'tablet', 'desktop'], true) ? $str('device') : null,
            city: $str('city'),
        );
    }

    public function isEmpty(): bool
    {
        return $this->site === null && $this->channel === null && $this->device === null && $this->city === null;
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return ['site' => $this->site?->publicId(), 'channel' => $this->channel, 'device' => $this->device, 'city' => $this->city];
    }

    /**
     * Condiciones para `sessions` en ClickHouse con parámetros enlazados.
     *
     * @return array{0: string, 1: array<string, scalar>}
     */
    public function sessionsSql(string $alias = ''): array
    {
        $p = $alias === '' ? '' : $alias.'.';
        $sql = '';
        $params = [];
        if ($this->site !== null) {
            $sql .= " AND {$p}site_id = {f_site:UInt32}";
            $params['f_site'] = $this->site->ch_id;
        }
        if ($this->channel !== null) {
            $sql .= " AND {$p}channel = {f_channel:String}";
            $params['f_channel'] = $this->channel;
        }
        if ($this->device !== null) {
            $sql .= " AND {$p}device_type = {f_device:String}";
            $params['f_device'] = $this->device;
        }
        if ($this->city !== null) {
            $sql .= " AND {$p}city = {f_city:String}";
            $params['f_city'] = $this->city;
        }

        return [$sql, $params];
    }

    /** Lo mismo para `events` (el canal y el dispositivo de cada evento). */
    public function eventsSql(): array
    {
        return $this->sessionsSql();
    }
}
