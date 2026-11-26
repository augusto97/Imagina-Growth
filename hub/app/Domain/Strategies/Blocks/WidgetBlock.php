<?php

declare(strict_types=1);

namespace App\Domain\Strategies\Blocks;

use App\Domain\Onsite\Actions\ChangeWidgetStatus;
use App\Domain\Onsite\Actions\CreateWidget;
use App\Domain\Onsite\Models\OnsiteWidget;
use App\Domain\Onsite\Support\WidgetSchema;
use App\Domain\Strategies\Support\Previews;
use App\Domain\Strategies\Support\Requirements;
use App\Domain\Strategies\Support\StrategyContext;
use App\Domain\Strategies\Support\StrategyNotReady;
use Illuminate\Validation\ValidationException;

/**
 * Un widget de En el sitio (modulos.md M12) creado desde los valores por defecto de su tipo (CreateWidget) y activado
 * con ChangeWidgetStatus. Cuenta cualquier widget de los tipos de la estrategia (también los que creó la persona a
 * mano): si ya hay uno activo, la estrategia está activa; si hay uno en pausa, activar lo reanuda.
 */
final class WidgetBlock implements StrategyBlock
{
    /** @param list<string> $kinds tipos que cuentan; el primero es el que se crea */
    public function __construct(public readonly array $kinds) {}

    public function kind(): string
    {
        return 'widget';
    }

    public function requirements(StrategyContext $ctx, array $options = []): array
    {
        return [];
    }

    public function state(StrategyContext $ctx): BlockState
    {
        $widgets = $ctx->widgets()->whereIn('kind', $this->kinds)->values();
        $refs = ['widgets' => array_values($widgets->pluck('id')->map(fn ($id) => (string) $id)->all())];
        $active = $widgets->firstWhere('status', 'active');
        if ($active !== null) {
            $siteDown = $active->site_id !== null && ! $ctx->connectedSites()->contains('id', $active->site_id);

            return BlockState::on('en-el-sitio?widget=wg_'.$active->id, $siteDown || ! $ctx->hasSite() ? ['site_down'] : [], $refs);
        }
        $paused = $widgets->firstWhere('status', 'paused');

        return $paused !== null ? BlockState::paused('en-el-sitio?widget=wg_'.$paused->id, $refs) : BlockState::off('en-el-sitio', $refs);
    }

    public function activate(StrategyContext $ctx, array $options, ?string $userId): array
    {
        $widgets = $ctx->widgets()->whereIn('kind', $this->kinds)->values();
        $active = $widgets->firstWhere('status', 'active');
        if ($active !== null) {
            return ['widgets' => [(string) $active->id]];
        }
        $existing = $widgets->firstWhere('status', 'paused');
        $widget = $existing !== null
            ? OnsiteWidget::query()->findOrFail((string) $existing->id)
            : app(CreateWidget::class)->handle(__('strategies.widgets.'.$this->kinds[0]), $this->kinds[0], $ctx->siteId(true), $userId, $this->overrides($ctx, $options));
        try {
            app(ChangeWidgetStatus::class)->handle($widget, 'active');
        } catch (ValidationException $e) {
            throw new StrategyNotReady(__('strategies.errors.widget_pending'), [], array_map(fn ($m) => array_values(array_map('strval', (array) $m)), $e->errors()), 'en-el-sitio?widget='.$widget->publicId());
        }

        return ['widgets' => [$widget->id]];
    }

    public function pause(StrategyContext $ctx, ?string $userId): array
    {
        foreach ($ctx->widgets()->whereIn('kind', $this->kinds)->where('status', 'active') as $row) {
            app(ChangeWidgetStatus::class)->handle(OnsiteWidget::query()->findOrFail((string) $row->id), 'paused');
        }

        return [];
    }

    public function preview(StrategyContext $ctx): array
    {
        $content = array_replace(WidgetSchema::defaults($this->kinds[0])['content'], $this->overrides($ctx, [])['content'] ?? []);

        return Previews::widget($this->kinds[0], $content);
    }

    /**
     * Valores recomendados según lo que el negocio tiene conectado: el popup pide el WhatsApp si hay un número
     * conectado (si no, el correo) y solo regala cupón con una tienda (el cupón lo crea WooCommerce); el botón de
     * WhatsApp usa el número de la cuenta conectada o el que llega en la activación.
     *
     * @param  array<string, mixed>  $options
     * @return array{content?: array<string, mixed>, consent_config?: array<string, mixed>, coupon_config?: array<string, mixed>}
     */
    private function overrides(StrategyContext $ctx, array $options): array
    {
        $kind = $this->kinds[0];
        $defaults = WidgetSchema::defaults($kind);
        $out = [];
        if ($kind === 'popup') {
            $store = $ctx->hasStore();
            $byWhatsapp = $ctx->hasChannel('whatsapp') || ! $ctx->hasChannel('email');
            $name = ['key' => 'first_name', 'type' => 'text', 'required' => false, 'label' => __('Tu nombre')];
            $field = $byWhatsapp ? ['key' => 'phone', 'type' => 'tel', 'required' => true, 'label' => __('Tu WhatsApp')] : ['key' => 'email', 'type' => 'email', 'required' => true, 'label' => __('Tu correo')];
            $out['content'] = ['fields' => [$name, $field]] + ($store ? [] : [
                'title' => __('strategies.widgets.popup_services_title'), 'body' => __('strategies.widgets.popup_services_body'),
                'cta' => __('strategies.widgets.popup_services_cta'), 'success' => __('strategies.widgets.popup_services_success'),
            ]) + ($byWhatsapp ? [] : ['body' => $store ? __('strategies.widgets.popup_email_body') : __('strategies.widgets.popup_services_body')]);
            $out['consent_config'] = ['channels' => [$byWhatsapp ? 'whatsapp' : 'email']];
            $out['coupon_config'] = ['enabled' => $store];
        } elseif ($kind === 'wa_widget') {
            $number = Requirements::waNumber($ctx, $options);
            $out['content'] = ['wa' => ['number' => $number ?? '', 'text' => (string) ($defaults['content']['wa']['text'] ?? '')]];
        }

        return $out;
    }
}
