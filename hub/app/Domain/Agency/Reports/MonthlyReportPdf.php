<?php

declare(strict_types=1);

namespace App\Domain\Agency\Reports;

use App\Domain\Agency\Models\AccountBranding as BrandingModel;
use App\Domain\Agency\Models\MonthlyReport;
use App\Domain\Workspaces\Contracts\Branding;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Support\Facades\Storage;
use Spatie\LaravelPdf\Facades\Pdf;

/**
 * El PDF del reporte mensual con la marca de la agencia (dompdf, sin Chromium).
 * Se guarda en el disco local privado: `agency/reports/<espacio>/<AAAA-MM>.pdf`.
 */
final class MonthlyReportPdf
{
    private const FALLBACK_COLOR = '#4F46E5';

    public function __construct(private readonly Branding $branding) {}

    public function render(Workspace $workspace, MonthlyReport $report): string
    {
        $path = 'agency/reports/'.$workspace->id.'/'.$report->period->format('Y-m').'.pdf';
        $brand = $this->branding->for($workspace);
        $bytes = Pdf::view('agency.monthly-report-pdf', [
            'report' => $report,
            'facts' => (array) $report->facts,
            'brand' => $brand,
            'logo' => self::logoDataUri((string) $workspace->account_id),
            'barColor' => self::barColor($brand->primaryColor),
        ])->format('a4')->generatePdfContent();
        Storage::disk('local')->put($path, $bytes);

        return $path;
    }

    /**
     * El color de las barras: el de la marca si contrasta al menos 3:1 con el
     * fondo blanco (marcas gráficas, WCAG 1.4.11); si no, el de la plataforma.
     */
    public static function barColor(string $hex): string
    {
        if (preg_match('/^#([0-9A-Fa-f]{2})([0-9A-Fa-f]{2})([0-9A-Fa-f]{2})$/', $hex, $m) !== 1) {
            return self::FALLBACK_COLOR;
        }
        $lum = 0.0;
        foreach ([[1, 0.2126], [2, 0.7152], [3, 0.0722]] as [$i, $w]) {
            $c = hexdec($m[$i]) / 255;
            $lum += $w * ($c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4);
        }

        return 1.05 / ($lum + 0.05) >= 3.0 ? strtoupper($hex) : self::FALLBACK_COLOR;
    }

    /** dompdf no descarga imágenes remotas: el logo va incrustado. */
    private static function logoDataUri(string $accountId): ?string
    {
        $path = BrandingModel::query()->whereKey($accountId)->value('logo_path');
        if (! is_string($path) || $path === '' || ! Storage::disk('public')->exists($path)) {
            return null;
        }
        $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            default => null, // dompdf no dibuja bien SVG ni WebP: va el nombre
        };

        return $mime === null ? null : 'data:'.$mime.';base64,'.base64_encode((string) Storage::disk('public')->get($path));
    }
}
