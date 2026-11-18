@php($color = \App\Domain\Agency\Reports\MonthlyReportPdf::barColor($brand->primaryColor))
<!DOCTYPE html>
<html lang="es">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $business }}</title></head>
<body style="margin:0;padding:0;background:#f4f4f5;font-family:Inter,Helvetica,Arial,sans-serif;color:#1f2937;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;padding:24px 0;">
<tr><td align="center">
<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;background:#ffffff;border-radius:8px;border-top:4px solid {{ $color }};">
<tr><td style="padding:24px 28px 8px;">
    @if ($brand->logoUrl)
        <img src="{{ $brand->logoUrl }}" alt="{{ $brand->name }}" style="max-height:36px;max-width:180px;">
    @else
        <div style="font-size:18px;font-weight:bold;">{{ $brand->name }}</div>
    @endif
</td></tr>
<tr><td style="padding:8px 28px 0;font-size:15px;line-height:1.55;">
    <p style="margin:0 0 12px;font-size:18px;font-weight:bold;">{{ __('Hola, :name', ['name' => explode(' ', $name)[0] ?? $name]) }}</p>
    <p style="margin:0 0 12px;">{{ __('Este es el reporte de :month de :business.', ['month' => $month, 'business' => $business]) }}</p>
    <p style="margin:0 0 4px;color:#6b7280;font-size:13px;">{{ __('Dinero que generó la plataforma') }}</p>
    <p style="margin:0 0 16px;font-size:28px;font-weight:bold;">{{ $amount }}</p>
    @foreach (preg_split('/\n{2,}/', trim($summary)) as $paragraph)
        <p style="margin:0 0 10px;">{{ $paragraph }}</p>
    @endforeach
    <p style="margin:20px 0;"><a href="{{ $url }}" style="display:inline-block;background:{{ $color }};color:#ffffff;text-decoration:none;padding:10px 18px;border-radius:6px;font-weight:bold;">{{ __('Ver el reporte') }}</a></p>
    <p style="margin:0 0 20px;color:#6b7280;font-size:13px;">{{ __('El PDF completo va adjunto.') }}</p>
</td></tr>
<tr><td style="padding:14px 28px 22px;border-top:1px solid #f3f4f6;color:#9ca3af;font-size:12px;">
    {{ $brand->name }}@if (! $brand->whiteLabel && $brand->name !== 'Imagina Growth') · {{ __('con Imagina Growth') }}@endif
    @if ($brand->supportEmail) · {{ $brand->supportEmail }}@endif
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
