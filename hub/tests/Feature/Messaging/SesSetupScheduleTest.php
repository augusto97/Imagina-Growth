<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

it('prepara el SES de la plataforma cada noche, sin consola, solo si hay credenciales (F5)', function () {
    $event = collect(app(Schedule::class)->events())->sole(fn (Event $e) => str_contains((string) $e->command, 'igr:ses:setup'));
    expect($event->expression)->toBe('47 3 * * *');

    config(['igr-messaging.ses.key' => '', 'igr-messaging.ses.secret' => '']);
    expect($event->filtersPass(app()))->toBeFalse();

    config(['igr-messaging.ses.key' => 'AKIAPRUEBA', 'igr-messaging.ses.secret' => 'secreto-de-prueba']);
    expect($event->filtersPass(app()))->toBeTrue();
});
