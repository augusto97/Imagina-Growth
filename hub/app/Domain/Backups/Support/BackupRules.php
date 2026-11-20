<?php

declare(strict_types=1);

namespace App\Domain\Backups\Support;

/**
 * Las listas blancas de un pedido de copias. Son las mismas que aplica `growth-backup-dispatch` del lado de root
 * (infra/backup), que es el que manda: el panel valida antes para explicar el error en el formulario.
 */
final class BackupRules
{
    /** Nombre de servidor o IPv4: etiquetas de letras, números y guiones (nunca empieza con guion). */
    public const HOST = '/^[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*$/D';

    /** Usuario del Storage Box (uXXXXXX o uXXXXXX-subN). */
    public const USER = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D';

    /** Carpeta relativa, hasta 5 niveles; cada parte empieza con letra o número (sin «..», «.ssh» ni «/»). */
    public const PATH = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}(\/[A-Za-z0-9][A-Za-z0-9._-]{0,63}){0,4}$/D';

    /** Heartbeat de Better Stack. */
    public const HEARTBEAT = '/^https:\/\/(uptime\.betterstack\.com|betteruptime\.com|uptime\.betteruptime\.com)\/api\/v1\/heartbeat\/[A-Za-z0-9_-]{8,128}$/D';

    /** Contraseñas y frase de cifrado: cualquier carácter menos los de control (saltos de línea, tabuladores…). */
    public const NO_CONTROL = '/^[^\p{Cc}]*$/uD';

    public const ENCRYPTION_MIN = 12;

    public const SECRET_MAX = 256;
}
