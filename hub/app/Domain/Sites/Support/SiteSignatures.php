<?php

declare(strict_types=1);

namespace App\Domain\Sites\Support;

use App\Domain\Sites\Models\Site;
use App\Ingest\IngestStream;
use App\Support\Hmac\RequestSignature;
use App\Support\Hmac\VerifiedSignature;
use App\Support\Tenancy\RedisKey;
use Illuminate\Http\Request;

/**
 * Qué firma usa el hub con cada sitio (docs/api-connector.md §2, S14):
 *
 * - **Entrante** (plugin → hub): acepta v2 siempre y v1 mientras `igr.signature.accept_v1` (IGR_SIGNATURE_ACCEPT_V1)
 *   esté prendido, para no cortar a los plugins anteriores a la 0.1.4. El nonce de una v2 se guarda en Redis mientras
 *   vale la ventana: la misma petición repetida se rechaza.
 * - **Saliente** (comandos hub → sitio): v2 solo si el plugin dijo que la entiende (versión 0.1.4 o más nueva en el
 *   canje, el latido o la configuración); si no, v1. Con v1 apagada, siempre v2.
 * - **Anuncio**: el canje y `/v1/s/config` le dicen al plugin qué versiones acepta el hub (`signature.versions`);
 *   el plugin firma con v2 solo cuando el hub la anuncia, y deja de aceptar comandos v1 cuando el hub ya no la usa.
 */
final class SiteSignatures
{
    public function __construct(private readonly IngestStream $stream) {}

    public static function acceptsV1(): bool
    {
        return (bool) config('igr.signature.accept_v1', true);
    }

    /** @return list<int> versiones que el hub acepta y usa */
    public static function versions(): array
    {
        return self::acceptsV1() ? [1, 2] : [2];
    }

    /** @return array{versions: list<int>} lo que viaja en el canje (§3.3) y en `/v1/s/config` (§5.1) */
    public static function advertised(): array
    {
        return ['versions' => self::versions()];
    }

    /** ¿El plugin del sitio entiende la v2? Según la última versión que informó. */
    public static function speaksV2(Site $site): bool
    {
        $version = $site->getAttribute('plugin_version');

        return is_string($version) && preg_match('/^\d+\.\d+\.\d+/', $version) === 1
            && version_compare($version, RequestSignature::V2_MIN_PLUGIN_VERSION, '>=');
    }

    /** Versión con la que el hub firma los comandos a ese sitio. */
    public static function versionFor(Site $site): int
    {
        return ! self::acceptsV1() || self::speaksV2($site) ? 2 : 1;
    }

    /**
     * Verifica la firma de una petición del sitio sobre los bytes recibidos (antes de descomprimir), con el método y
     * la ruta y la consulta tal como llegaron.
     */
    public function verify(Request $request, Site $site, string $body): ?VerifiedSignature
    {
        $tolerance = (int) config('igr.signature.tolerance_seconds');
        $verified = RequestSignature::verify(
            $request->header('X-IGR-Signature'),
            $request->getMethod(),
            $request->getRequestUri(),
            $body,
            $site->verificationSecrets(),
            self::acceptsV1(),
            $tolerance,
        );
        if ($verified === null || $verified->version < 2) {
            return $verified;
        }

        return $this->claimNonce($site, (string) $verified->nonce, $tolerance) ? $verified : null;
    }

    /** Guarda el nonce el doble de la ventana (una firma vale de t − ventana a t + ventana); false si ya estaba. */
    private function claimNonce(Site $site, string $nonce, int $tolerance): bool
    {
        /** @var \Redis $client */
        $client = $this->stream->connection()->client();
        $key = RedisKey::for($site->workspace_id, 'signonce:'.$site->id.':'.$nonce);

        return $client->set($key, '1', ['nx', 'ex' => max(60, 2 * $tolerance)]) === true;
    }
}
