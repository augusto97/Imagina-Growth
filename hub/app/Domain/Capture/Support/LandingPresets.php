<?php

declare(strict_types=1);

namespace App\Domain\Capture\Support;

use App\Domain\Templates\Support\BusinessProfile;
use App\Domain\Workspaces\Models\Workspace;

/**
 * Textos y campos de partida de una página de captura según para qué es (el asistente de «Nueva página», modulos.md
 * M12): que me contacten, pedir cotización, agendar una valoración, una promoción o inscribirse a un evento. Son textos
 * de ejemplo sin cifras inventadas; el negocio los cambia o los escribe con la IA en el editor. Lo que la persona
 * necesita va en preguntas propias de texto libre (el campo «servicio» del producto es una lista y pide sus opciones).
 */
final class LandingPresets
{
    /** El diseño que mejor le va a cada objetivo (se puede cambiar en el asistente y en el editor). */
    public const TEMPLATE = ['contact' => 'split', 'quote' => 'split', 'appointment' => 'centered', 'offer' => 'hero', 'event' => 'hero'];

    /**
     * @return array{content: array<string, mixed>, fields: list<array<string, mixed>>}
     */
    public static function for(string $goal, Workspace $workspace): array
    {
        $business = BusinessProfile::for($workspace)['name'] ?: $workspace->name;
        $name = ['key' => 'name', 'label' => __('Nombre'), 'type' => 'text', 'required' => true];
        $phone = ['key' => 'phone', 'label' => __('WhatsApp'), 'type' => 'tel', 'required' => true];
        $email = ['key' => 'email', 'label' => __('Correo'), 'type' => 'email', 'required' => false];

        return match ($goal) {
            'quote' => [
                'content' => [
                    'eyebrow' => __('Cotización sin costo'),
                    'title' => __('Recibe tu cotización sin costo'),
                    'body' => __('Cuéntanos qué necesitas y te enviamos una propuesta clara, con precios y tiempos, por WhatsApp o correo.'),
                    'benefits' => [__('Precio claro, sin sorpresas'), __('Te respondemos el mismo día hábil'), __('Sin compromiso de compra')],
                    'faq' => [
                        ['q' => __('¿La cotización tiene costo?'), 'a' => __('No. Te la enviamos sin compromiso para que decidas con calma.')],
                        ['q' => __('¿Qué necesito para cotizar?'), 'a' => __('Cuéntanos qué necesitas, la cantidad o el tamaño y la ciudad. Si falta algo, te preguntamos.')],
                    ],
                    'button' => __('Quiero mi cotización'),
                    'success_title' => __('¡Gracias! Ya estamos preparando tu cotización'),
                    'success_message' => __('Te la enviamos muy pronto por WhatsApp o correo. Si es urgente, escríbenos.'),
                ],
                'fields' => [$name, $phone, $email,
                    ['key' => 'custom_que_necesitas', 'label' => __('¿Qué necesitas cotizar?'), 'type' => 'text', 'required' => false],
                    ['key' => 'message', 'label' => __('Cuéntanos los detalles'), 'type' => 'textarea', 'required' => false]],
            ],
            'appointment' => [
                'content' => [
                    'eyebrow' => __('Agenda abierta'),
                    'title' => __('Agenda tu valoración'),
                    'body' => __('Déjanos tus datos y te escribimos para elegir el día y la hora que mejor te queden.'),
                    'benefits' => [__('Te atiende una persona experta'), __('Eliges el horario que te quede mejor'), __('Te recordamos la cita por WhatsApp')],
                    'faq' => [
                        ['q' => __('¿Puedo cambiar la fecha?'), 'a' => __('Sí. Escríbenos por WhatsApp y la movemos al horario que necesites.')],
                        ['q' => __('¿Qué debo llevar?'), 'a' => __('Te lo contamos al confirmar la cita, según el servicio que elijas.')],
                    ],
                    'button' => __('Quiero agendar'),
                    'success_title' => __('¡Gracias! Te escribimos para agendar'),
                    'success_message' => __('Muy pronto te contactamos para confirmar el día y la hora.'),
                ],
                'fields' => [$name, $phone, $email,
                    ['key' => 'custom_servicio', 'label' => __('¿Qué servicio te interesa?'), 'type' => 'text', 'required' => false]],
            ],
            'offer' => [
                'content' => [
                    'eyebrow' => __('Por tiempo limitado'),
                    'title' => __('Aprovecha nuestra promoción'),
                    'body' => __('Déjanos tus datos y te enviamos los detalles de la promoción por WhatsApp antes de que se acabe.'),
                    'benefits' => [__('Te enviamos la promoción al instante'), __('Condiciones claras, sin letra pequeña'), __('Cupos limitados')],
                    'faq' => [
                        ['q' => __('¿Hasta cuándo es válida?'), 'a' => __('Te contamos las condiciones y la fecha límite en el mensaje.')],
                        ['q' => __('¿Cómo la uso?'), 'a' => __('Con el mensaje que te enviamos, en la tienda o por WhatsApp.')],
                    ],
                    'button' => __('Quiero la promoción'),
                    'success_title' => __('¡Listo! Revisa tu WhatsApp'),
                    'success_message' => __('Te enviamos los detalles de la promoción en unos minutos.'),
                ],
                'fields' => [$name, $phone, $email],
            ],
            'event' => [
                'content' => [
                    'eyebrow' => __('Cupos limitados'),
                    'title' => __('Reserva tu cupo'),
                    'body' => __('Inscríbete y te enviamos la confirmación con la fecha, la hora y el lugar por WhatsApp o correo.'),
                    'benefits' => [__('Confirmación inmediata'), __('Te recordamos el día anterior'), __('Toda la información en un solo mensaje')],
                    'faq' => [
                        ['q' => __('¿Tiene costo?'), 'a' => __('Te contamos todos los detalles en la confirmación.')],
                        ['q' => __('¿Puedo cancelar mi cupo?'), 'a' => __('Sí. Escríbenos por WhatsApp y lo liberamos para otra persona.')],
                    ],
                    'button' => __('Reservar mi cupo'),
                    'success_title' => __('¡Tu cupo está reservado!'),
                    'success_message' => __('Te enviamos la confirmación con todos los detalles.'),
                ],
                'fields' => [$name, $phone, $email],
            ],
            default => [
                'content' => [
                    'eyebrow' => __('Respuesta el mismo día'),
                    'title' => __('Te ayudamos hoy mismo'),
                    'body' => __('Déjanos tus datos y una persona de :business te escribe por WhatsApp para resolver tus dudas.', ['business' => $business]),
                    'benefits' => [__('Te respondemos el mismo día hábil'), __('Asesoría sin compromiso'), __('Por WhatsApp o correo, como prefieras')],
                    'faq' => [
                        ['q' => __('¿Tiene algún costo?'), 'a' => __('No. Te contactamos para entender lo que necesitas y darte la información sin compromiso.')],
                        ['q' => __('¿Cuándo me escriben?'), 'a' => __('Normalmente el mismo día hábil, en nuestro horario de atención.')],
                    ],
                    'button' => __('Quiero que me contacten'),
                ],
                'fields' => [$name, $phone, $email, ['key' => 'message', 'label' => __('¿En qué te podemos ayudar?'), 'type' => 'textarea', 'required' => false]],
            ],
        };
    }
}
