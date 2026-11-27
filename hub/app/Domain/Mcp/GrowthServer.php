<?php

declare(strict_types=1);

namespace App\Domain\Mcp;

use App\Domain\Mcp\Tools\DraftCampaignTool;
use App\Domain\Mcp\Tools\DraftSegmentTool;
use App\Domain\Mcp\Tools\GetContactSummaryTool;
use App\Domain\Mcp\Tools\GetKpisTool;
use App\Domain\Mcp\Tools\GetSetupStatusTool;
use App\Domain\Mcp\Tools\ListDeliveryIssuesTool;
use App\Domain\Mcp\Tools\ListInsightsTool;
use App\Domain\Mcp\Tools\ListSegmentsTool;
use App\Domain\Mcp\Tools\RunMetricQueryTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * Servidor MCP de Imagina Growth (M19, docs/api-publica.md §11): deja que Claude u otro
 * agente consulte los datos de un espacio de trabajo con un token de alcance `mcp`.
 * Las herramientas de escritura solo crean borradores que alguien aprueba en el panel.
 */
#[Name('Imagina Growth')]
#[Version('1.0.0')]
#[Instructions('Datos de marketing y ventas de un negocio en Imagina Growth (tienda WooCommerce o sitio de servicios en LATAM). Usa get_kpis para el resumen del periodo, run_metric_query para preguntas de métricas (mismas definiciones del tablero), list_segments, list_insights (hallazgos con impacto estimado) y get_contact_summary. Para revisar la configuración usa get_setup_status (qué está conectado y qué falta) y list_delivery_issues (mensajes que no salieron y por qué). Las cifras salen de los datos: no las inventes ni las redondees de más. draft_campaign y draft_segment solo crean borradores: dile a la persona que los revise y apruebe en el enlace que devuelven. Montos en la moneda del negocio; fechas en su zona horaria.')]
final class GrowthServer extends Server
{
    protected array $tools = [
        GetKpisTool::class,
        RunMetricQueryTool::class,
        ListSegmentsTool::class,
        ListInsightsTool::class,
        GetContactSummaryTool::class,
        GetSetupStatusTool::class,
        ListDeliveryIssuesTool::class,
        DraftCampaignTool::class,
        DraftSegmentTool::class,
    ];
}
