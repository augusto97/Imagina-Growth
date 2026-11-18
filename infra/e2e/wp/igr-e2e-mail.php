<?php
/**
 * Plugin Name: Imagina Growth · correo de prueba (E2E)
 * Description: Solo para las pruebas de punta a punta: wp_mail() responde como si el correo hubiera salido,
 * para que Contact Form 7 termine en «mail_sent» en un WordPress sin servidor de correo.
 */

declare(strict_types=1);

add_filter( 'pre_wp_mail', static fn () => true );
