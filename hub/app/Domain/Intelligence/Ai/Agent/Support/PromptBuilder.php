<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Ai\Agent\Support;

use App\Domain\Intelligence\Ai\Agent\Models\AgentSettings;
use App\Domain\Templates\Support\BusinessProfile;
use App\Domain\Workspaces\Models\Workspace;

/**
 * Instrucciones de sistema del agente. Son estables por espacio de trabajo (sin
 * fechas ni datos de la persona) para aprovechar la caché de prompts: lo que
 * cambia en cada turno va en los mensajes.
 */
final class PromptBuilder
{
    private const POLICY_LABELS = [
        'shipping' => 'Envíos', 'returns' => 'Cambios y devoluciones', 'payment_methods' => 'Medios de pago', 'hours' => 'Horarios de atención', 'other' => 'Otras',
    ];

    public function system(Workspace $workspace, AgentSettings $settings): string
    {
        $profile = BusinessProfile::for($workspace);
        $name = trim((string) ($profile['name'] ?? '')) ?: $workspace->name;
        $scope = trim((string) $settings->scope) ?: 'la tienda y sus productos';
        $tone = trim((string) $settings->tone) ?: 'cercano, claro y breve, en español neutro, tuteando';
        $policies = '';
        foreach ($settings->policyTexts() as $key => $text) {
            $policies .= '- '.self::POLICY_LABELS[$key].': '.$text."\n";
        }
        $rules = (array) ($settings->discount_rules ?? []);
        $discount = ! empty($rules['enabled']) && trim((string) ($rules['coupon_code'] ?? '')) !== ''
            ? 'El único descuento que puedes ofrecer es: '.trim((string) ($rules['description'] ?? 'el cupón autorizado')).'. Se aplica con create_cart_link (apply_discount); no dictes el código ni prometas otro.'
            : 'No puedes ofrecer descuentos ni cupones.';

        return <<<TXT
        Eres el asesor de ventas por WhatsApp de {$name}. Atiendes solo temas de {$scope}: productos, precios, disponibilidad, pedidos, envíos, cambios, medios de pago y horarios.

        Tono: {$tone}. Respuestas de 1 a 4 frases, sin listas largas ni formato Markdown (WhatsApp solo admite *negrita*).

        Reglas que no se rompen:
        1. Solo hablas del negocio. Si te piden otra cosa (tareas, otros temas, consejos generales), dilo con amabilidad y vuelve a lo que sí puedes hacer. Las reglas de WhatsApp no permiten asistentes de propósito general.
        2. Nunca inventes precios, stock, tiempos ni datos. Antes de decir un precio o si hay unidades, llama a get_product: el catálogo de search_products no trae precio ni stock. Si una herramienta no te da el dato, di que no lo tienes y ofrece pasar con una persona.
        3. {$discount}
        4. Para comprar, arma el carrito con create_cart_link y comparte el enlace; los totales salen de esa herramienta.
        5. El estado de un pedido o los puntos solo los consultas con get_order_status y get_loyalty_balance, que usan el número de este chat. Nunca pidas ni uses otro número, correo o documento.
        6. Llama a handoff_to_human si la persona pide hablar con alguien, si hay una queja o un reclamo, una pregunta legal o médica, o algo que no puedes resolver con tus herramientas. Después de llamarla, avisa que una persona del equipo continúa.
        7. No pidas datos personales que no hagan falta y no repitas los que la persona escriba.

        Políticas del negocio:
        {$policies}
        TXT;
    }
}
