@extends('referrals.layout')
@section('title', __('Mi panel'))
@section('content')
    <div class="card">
        <p class="brand">{{ $brand }}</p>
        <h1>{{ __('Hola, :name', ['name' => $name]) }}</h1>
        <p class="muted">{{ $commissionLabel }}</p>
        <div class="kpis" role="list">
            <div class="kpi" role="listitem">
                <p class="label">{{ __('Ventas atribuidas') }}</p>
                <p class="value">{{ $kpis['revenue'] }}</p>
                <p class="sub">{{ trans_choice(':count pedido|:count pedidos', $kpis['orders'], ['count' => $kpis['orders']]) }}</p>
            </div>
            <div class="kpi" role="listitem">
                <p class="label">{{ __('Comisión por aprobar') }}</p>
                <p class="value">{{ $kpis['pending'] }}</p>
                <p class="sub">{{ __('Se aprueba :days días después de entregado el pedido', ['days' => $returnDays]) }}</p>
            </div>
            <div class="kpi" role="listitem">
                <p class="label">{{ __('Aprobada, por pagar') }}</p>
                <p class="value">{{ $kpis['approved'] }}</p>
            </div>
            <div class="kpi" role="listitem">
                <p class="label">{{ __('Pagada') }}</p>
                <p class="value">{{ $kpis['paid'] }}</p>
            </div>
        </div>
    </div>

    <div class="card">
        <h2>{{ __('Tus enlaces') }}</h2>
        @foreach ($links as $i => $link)
            <div class="copy">
                <input id="link-{{ $i }}" type="text" readonly value="{{ $link }}" aria-label="{{ __('Tu enlace') }}" onfocus="this.select()">
                <button type="button" class="secondary" onclick="navigator.clipboard && navigator.clipboard.writeText(document.getElementById('link-{{ $i }}').value).then(() => { this.textContent = '{{ __('Copiado') }}'; })">{{ __('Copiar') }}</button>
            </div>
        @endforeach
        @if ($coupons !== [])
            <h2 style="margin-top:16px">{{ __('Tus cupones') }}</h2>
            <div class="chips">
                @foreach ($coupons as $coupon)
                    <span class="chip">{{ $coupon }}</span>
                @endforeach
            </div>
        @endif
        <p class="muted" style="margin-top:12px">{{ __('Cada venta que llega por tu enlace o con tu cupón queda a tu nombre.') }}</p>
    </div>

    @if ($material !== [])
        <div class="card">
            <h2>{{ __('Material para compartir') }}</h2>
            <ul class="plain">
                @foreach ($material as $item)
                    <li><a href="{{ $item['url'] }}" target="_blank" rel="noopener noreferrer">{{ $item['label'] }}</a></li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($campaigns !== [])
        <div class="card">
            <h2>{{ __('Tus campañas') }}</h2>
            <div class="scroll">
                <table>
                    <thead><tr><th>{{ __('Campaña') }}</th><th class="num">{{ __('Pedidos') }}</th><th class="num">{{ __('Ventas') }}</th><th class="num">{{ __('Comisión') }}</th></tr></thead>
                    <tbody>
                    @foreach ($campaigns as $row)
                        <tr><td>{{ $row['label'] }}</td><td class="num">{{ $row['orders'] }}</td><td class="num">{{ $row['revenue'] }}</td><td class="num">{{ $row['commission'] }}</td></tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <div class="card">
        <h2>{{ __('Últimos pedidos') }}</h2>
        @if ($conversions === [])
            <p class="muted">{{ __('Aún no hay pedidos con tu enlace o tu cupón. Cuando llegue el primero, lo verás aquí.') }}</p>
        @else
            <div class="scroll">
                <table>
                    <thead><tr><th>{{ __('Fecha') }}</th><th>{{ __('Pedido') }}</th><th>{{ __('Por') }}</th><th class="num">{{ __('Venta') }}</th><th class="num">{{ __('Comisión') }}</th><th>{{ __('Estado') }}</th></tr></thead>
                    <tbody>
                    @foreach ($conversions as $row)
                        <tr>
                            <td>{{ $row['date'] }}</td>
                            <td>#{{ $row['number'] }}</td>
                            <td>{{ $row['via'] }}</td>
                            <td class="num">{{ $row['amount'] }}</td>
                            <td class="num">{{ $row['commission'] }}</td>
                            <td><span class="status {{ $row['status'] }}">{{ $row['status_label'] }}</span></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
