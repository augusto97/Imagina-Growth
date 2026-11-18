<?php

/*
 * Reseñas, NPS y prueba social (modulos.md M15).
 */
return [
    // Origen de las páginas públicas (/r/{token}, /r/nps/{token}) y de los medios (/r/m/{key}).
    'public_url' => env('IGR_REVIEWS_URL', env('APP_URL')),

    // Disco privado de las fotos y videos (nunca uno público: se sirven por /r/m/ solo si la reseña está publicada).
    'disk' => env('IGR_REVIEWS_DISK', 'local'),

    'uploads' => [
        'max_files' => 5,
        'max_videos' => 1,
        'image_max_kb' => 10240,          // antes de reducirla
        'video_max_kb' => 20480,          // el post_max_size de producción es 21 MB
        'image_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
        'video_mimes' => ['video/mp4', 'video/quicktime'],
        'max_pixels' => 50_000_000,       // protección contra imágenes «bomba»
        'image_max_side' => 1600,
        'thumb_side' => 400,
        'jpeg_quality' => 82,
    ],

    // Envíos de solicitudes y encuestas por corrida del scheduler.
    'send_batch' => 500,

    // Una solicitud que no se pudo enviar en estos días vence.
    'request_expire_days' => 30,
];
