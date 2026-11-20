<?php

declare(strict_types=1);

namespace Tests\Feature\Connections\Support;

/** Alcances que Google devuelve cuando la persona acepta todo en la conexión de Medición. */
final class GoogleScopes
{
    public const ALL = 'openid https://www.googleapis.com/auth/userinfo.email https://www.googleapis.com/auth/datamanager https://www.googleapis.com/auth/adwords https://www.googleapis.com/auth/analytics.edit';

    public const CALENDAR = 'openid https://www.googleapis.com/auth/userinfo.email https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/calendar.freebusy';
}
