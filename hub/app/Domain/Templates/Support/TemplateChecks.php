<?php

declare(strict_types=1);

namespace App\Domain\Templates\Support;

use App\Domain\Templates\Models\Template;
use App\Domain\Templates\Rendering\WhatsAppComponents;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * Revisiones de una plantilla para el editor (modulos.md M08, cumplimiento.md §8):
 * `error` impide marcarla lista; `warning` solo avisa.
 *
 * - Correo de marketing: pie legal con quién envía, dirección o contacto, el
 *   motivo y el enlace de baja (`{{ enlaces.baja }}`); asunto presente y corto.
 * - SMS: segmentos (GSM-7 o UCS-2) y, en marketing, cómo darse de baja.
 * - WhatsApp: plantilla aprobada y cada parámetro con su variable.
 */
final class TemplateChecks
{
    /** Caracteres de GSM-7 (alfabeto básico más la extensión, que cuenta doble). */
    private const GSM7 = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";

    private const GSM7_EXT = '^{}\\[~]|€';

    /** @return list<array{level: string, code: string, message: string}> */
    public static function run(Template $template, Workspace $workspace): array
    {
        return match ($template->channel) {
            'email' => self::email($template, $workspace),
            'sms' => self::sms($template),
            'whatsapp' => self::whatsapp($template, $workspace),
            default => [],
        };
    }

    /** @return list<array{level: string, code: string, message: string}> */
    private static function email(Template $template, Workspace $workspace): array
    {
        $out = [];
        $body = (string) ($template->body_html ?: $template->body_mjml);
        $subject = trim((string) $template->subject);
        if ($subject === '') {
            $out[] = self::item('error', 'subject_missing', __('Falta el asunto.'));
        } elseif (mb_strlen($subject) > 80) {
            $out[] = self::item('warning', 'subject_long', __('El asunto tiene más de 80 caracteres: en el celular se corta.'));
        }
        if (mb_strlen((string) $template->preheader) > 120) {
            $out[] = self::item('warning', 'preheader_long', __('El preheader tiene más de 120 caracteres.'));
        }
        if (trim($body) === '') {
            $out[] = self::item('error', 'body_missing', __('El correo está vacío.'));

            return $out;
        }
        if (! $template->isMarketing()) {
            return $out;
        }
        $profile = BusinessProfile::for($workspace);
        if (! self::uses($body, 'enlaces.baja')) {
            $out[] = self::item('error', 'unsubscribe_missing', __('Falta el enlace para darse de baja ({{ enlaces.baja }}). Es obligatorio en los correos de marketing.'));
        }
        if (! self::uses($body, 'tienda.nombre') && ($profile['name'] === '' || ! str_contains($body, $profile['name']))) {
            $out[] = self::item('warning', 'sender_missing', __('Identifica quién envía el correo ({{ tienda.nombre }}) en el pie.'));
        }
        $hasAddress = self::uses($body, 'tienda.direccion') || self::uses($body, 'tienda.contacto')
            || ($profile['address'] !== '' && str_contains($body, $profile['address']));
        if (! $hasAddress) {
            $out[] = self::item('warning', 'address_missing', __('Incluye la dirección física o un contacto del negocio en el pie ({{ tienda.direccion }}).'));
        } elseif (! BusinessProfile::isComplete($workspace)) {
            $out[] = self::item('warning', 'profile_incomplete', __('Completa la dirección o el contacto en «Datos del negocio»: hoy el pie los mostraría vacíos.'));
        }
        if (! str_contains(mb_strtolower(strip_tags($body)), 'porque') && ! str_contains(mb_strtolower(strip_tags($body)), 'motivo')) {
            $out[] = self::item('warning', 'reason_missing', __('Explica por qué recibe el correo (por ejemplo: «Recibes este correo porque aceptaste recibir novedades»).'));
        }

        return $out;
    }

    /** @return list<array{level: string, code: string, message: string}> */
    private static function sms(Template $template): array
    {
        $text = (string) $template->body_text;
        if (trim($text) === '') {
            return [self::item('error', 'body_missing', __('El SMS está vacío.'))];
        }
        $out = [];
        $info = self::smsSegments($text);
        if ($info['segments'] > 3) {
            $out[] = self::item('warning', 'sms_long', __('El SMS ocupa :n partes y cada una se cobra. Acórtalo.', ['n' => $info['segments']]));
        }
        if ($info['encoding'] === 'UCS-2') {
            $out[] = self::item('warning', 'sms_unicode', __('Las tildes, eñes y emojis bajan el límite de 160 a 70 caracteres por parte.'));
        }
        if ($template->isMarketing() && ! preg_match('/\b(baja|stop|salir)\b/i', $text)) {
            $out[] = self::item('error', 'sms_optout_missing', __('Los SMS de marketing deben decir cómo darse de baja (por ejemplo: «Responde BAJA para no recibir más»).'));
        }

        return $out;
    }

    /** @return list<array{level: string, code: string, message: string}> */
    private static function whatsapp(Template $template, Workspace $workspace): array
    {
        $wa = $template->wa_template_id !== null
            ? DB::table('wa_templates')->where('workspace_id', $workspace->id)->where('id', $template->wa_template_id)->first()
            : null;
        if ($wa === null) {
            return [self::item('error', 'wa_template_missing', __('Elige una plantilla aprobada de WhatsApp.'))];
        }
        $out = [];
        if (strtoupper((string) $wa->status) !== 'APPROVED') {
            $out[] = self::item('error', 'wa_not_approved', __('La plantilla de WhatsApp todavía no está aprobada por Meta.'));
        }
        if ($template->isMarketing() !== (strtoupper((string) $wa->category) === 'MARKETING')) {
            $out[] = self::item('warning', 'wa_category', __('La categoría no coincide con la de Meta. No disfraces marketing de utilidad: Meta lo reclasifica y puede bloquear la plantilla.'));
        }
        $components = json_decode((string) $wa->components, true);
        $slots = WhatsAppComponents::slots(is_array($components) ? array_values($components) : []);
        $mapping = $template->variables ?? [];
        $missing = [];
        foreach (['header', 'body'] as $part) {
            foreach ($slots[$part] as $key) {
                if (! is_string($mapping[$part][$key] ?? null) || trim($mapping[$part][$key]) === '') {
                    $missing[] = '{{'.$key.'}}';
                }
            }
        }
        foreach ($slots['buttons'] as $button) {
            if ($button['needs_value'] && trim((string) ($mapping['buttons'][(string) $button['index']] ?? '')) === '') {
                $missing[] = __('botón :n', ['n' => $button['index'] + 1]);
            }
        }
        if ($slots['header_media'] !== null && trim((string) ($mapping['header_media'] ?? '')) === '') {
            $missing[] = __('archivo del encabezado');
        }
        if ($missing !== []) {
            $out[] = self::item('error', 'wa_variables_missing', __('Falta la variable de: :list.', ['list' => implode(', ', $missing)]));
        }

        return $out;
    }

    /** @return array{encoding: string, length: int, segments: int} */
    public static function smsSegments(string $text): array
    {
        $gsm = true;
        $length = 0;
        foreach (mb_str_split($text) as $char) {
            if (str_contains(self::GSM7_EXT, $char)) {
                $length += 2;
            } elseif (str_contains(self::GSM7, $char)) {
                $length++;
            } else {
                $gsm = false;
            }
        }
        if (! $gsm) {
            $length = mb_strlen($text);

            return ['encoding' => 'UCS-2', 'length' => $length, 'segments' => $length <= 70 ? 1 : (int) ceil($length / 67)];
        }

        return ['encoding' => 'GSM-7', 'length' => $length, 'segments' => $length <= 160 ? 1 : (int) ceil($length / 153)];
    }

    /** @param list<array{level: string, code: string, message: string}> $checks */
    public static function hasErrors(array $checks): bool
    {
        return array_filter($checks, fn (array $c) => $c['level'] === 'error') !== [];
    }

    private static function uses(string $body, string $variable): bool
    {
        return (bool) preg_match('/\{\{[^}]*'.preg_quote($variable, '/').'/', $body);
    }

    /** @return array{level: string, code: string, message: string} */
    private static function item(string $level, string $code, string $message): array
    {
        return ['level' => $level, 'code' => $code, 'message' => $message];
    }
}
