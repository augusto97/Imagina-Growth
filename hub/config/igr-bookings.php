<?php

/*
 * Agenda de citas (modulos.md M18).
 */
return [
    // Paso entre horarios ofrecidos (minutos).
    'slot_step_min' => (int) env('IGR_BOOKING_STEP_MIN', 15),
    // Recordatorios antes de la cita (horas) y no asistencia automática tras el final (minutos).
    'reminders' => [24, 2],
    'no_show_after_min' => 30,
    // Google Calendar (OAuth por persona) [verificar con una cuenta real de Google Cloud].
    'google' => [
        'client_id' => env('IGR_GOOGLE_CALENDAR_CLIENT_ID'),
        'client_secret' => env('IGR_GOOGLE_CALENDAR_CLIENT_SECRET'),
        'redirect' => env('IGR_GOOGLE_CALENDAR_REDIRECT'),
        'auth_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
        'token_url' => 'https://oauth2.googleapis.com/token',
        'api' => 'https://www.googleapis.com/calendar/v3',
        'scope' => 'https://www.googleapis.com/auth/calendar.events https://www.googleapis.com/auth/calendar.freebusy',
    ],
];
