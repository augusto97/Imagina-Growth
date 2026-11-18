<?php

declare(strict_types=1);

namespace App\Domain\Onsite\Support;

use App\Domain\Onsite\Models\OnsiteWidget;
use App\Domain\Segments\Models\Segment;
use App\Domain\Sites\Models\Site;
use App\Domain\Templates\Models\Template;
use App\Support\Phone\Phone;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Valida y normaliza lo que manda el constructor (SPA) para un widget. Deja solo
 * las claves conocidas, con tipos fijos, para que widgets.json nunca publique
 * algo inesperado. `forActivation` agrega lo que hace falta para mostrarlo en el
 * sitio (campos de contacto, número de WhatsApp, premios, preguntas).
 */
final class WidgetValidator
{
    /** @var array<string, list<string>> */
    private array $errors = [];

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed> atributos del modelo más `quiz` (o null)
     */
    public function validate(OnsiteWidget $widget, array $input): array
    {
        $this->errors = [];
        $kind = $widget->kind;
        $out = [];

        if (array_key_exists('name', $input)) {
            $name = is_string($input['name']) ? trim($input['name']) : '';
            if ($name === '' || mb_strlen($name) > 160) {
                $this->error('name', __('Escribe un nombre de hasta 160 caracteres.'));
            }
            $out['name'] = $name;
        }
        if (array_key_exists('site_id', $input)) {
            $out['site_id'] = $this->site($input['site_id']);
        }
        if (array_key_exists('priority', $input)) {
            $out['priority'] = max(0, min(1000, (int) $input['priority']));
        }
        if (array_key_exists('targeting', $input)) {
            $out['targeting'] = $this->targeting(is_array($input['targeting']) ? $input['targeting'] : []);
        }
        if (array_key_exists('variants', $input)) {
            $variants = $this->variants($kind, is_array($input['variants']) ? $input['variants'] : []);
            $out['variants'] = $variants;
            $out['design'] = $variants[0]['design'];
            $out['content'] = $variants[0]['content'];
        }
        if (array_key_exists('consent_config', $input)) {
            $out['consent_config'] = $this->consent($kind, is_array($input['consent_config']) ? $input['consent_config'] : []);
        }
        if (array_key_exists('coupon_config', $input)) {
            $out['coupon_config'] = $kind === 'spin_wheel'
                ? $this->prizes(is_array($input['coupon_config']) ? $input['coupon_config'] : [])
                : $this->gift(is_array($input['coupon_config']) ? $input['coupon_config'] : []);
        }
        if (array_key_exists('quiz', $input) && $kind === 'quiz') {
            $out['quiz'] = $this->quiz(is_array($input['quiz']) ? $input['quiz'] : []);
        }
        foreach (['starts_at', 'ends_at'] as $key) {
            if (array_key_exists($key, $input)) {
                $out[$key] = $this->date($key, $input[$key]);
            }
        }
        $starts = $out['starts_at'] ?? $widget->starts_at;
        $ends = $out['ends_at'] ?? $widget->ends_at;
        if ($starts !== null && $ends !== null && ! $ends->greaterThan($starts)) {
            $this->error('ends_at', __('La fecha de fin debe ser posterior a la de inicio.'));
        }

        $this->throwIfErrors();

        return $out;
    }

    /** Lo mínimo para activar el widget: si falta algo, ValidationException con el motivo. */
    public function assertActivatable(OnsiteWidget $widget): void
    {
        $this->errors = [];
        foreach (WidgetSchema::variants($widget) as $variant) {
            $content = $variant['content'];
            $keys = array_column((array) ($content['fields'] ?? []), 'key');
            if ($widget->kind === 'wa_widget') {
                if (Phone::toE164('+'.ltrim((string) ($content['wa']['number'] ?? ''), '+'), 'CO') === null) {
                    $this->error('wa.number', __('Escribe el número de WhatsApp con el indicativo del país (por ejemplo, 573001234567).'));
                }

                continue;
            }
            $contactOptional = ($widget->kind === 'quiz' && (bool) ($content['contact_optional'] ?? false))
                || ($widget->kind === 'bar' && $keys === [] && ($content['url'] ?? '') !== '');
            if (! $contactOptional && ! in_array('email', $keys, true) && ! in_array('phone', $keys, true)) {
                $this->error('fields', __('La variante :v debe pedir un correo o un WhatsApp.', ['v' => strtoupper($variant['key'])]));
            }
            if ($widget->kind === 'two_step') {
                $second = array_column((array) ($content['step2']['fields'] ?? []), 'key');
                if ($second === []) {
                    $this->error('step2.fields', __('El segundo paso debe pedir al menos un dato.'));
                }
            }
            if (trim((string) ($content['title'] ?? '')) === '' || trim((string) ($content['cta'] ?? '')) === '') {
                $this->error('content', __('Cada variante necesita un título y el texto del botón.'));
            }
        }
        if ($widget->kind === 'spin_wheel') {
            $prizes = (array) ($widget->coupon_config['prizes'] ?? []);
            if (count($prizes) < 2 || array_sum(array_map(fn ($p) => (int) ($p['weight'] ?? 0), $prizes)) <= 0) {
                $this->error('coupon_config.prizes', __('La ruleta necesita al menos 2 premios y probabilidades que sumen más de 0.'));
            }
        }
        if ($widget->kind === 'quiz' && count((array) ($widget->quiz->questions ?? [])) === 0) {
            $this->error('quiz.questions', __('El quiz necesita al menos una pregunta.'));
        }
        $this->throwIfErrors();
    }

    private function site(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $site = is_string($value) ? Site::findByPublicId($value) : null;
        if ($site === null) {
            $this->error('site_id', __('El sitio no existe en este espacio de trabajo.'));
        }

        return $site?->id;
    }

    /**
     * @param  array<string, mixed>  $t
     * @return array<string, mixed>
     */
    private function targeting(array $t): array
    {
        $trigger = is_array($t['trigger'] ?? null) ? $t['trigger'] : [];
        $type = in_array($trigger['type'] ?? null, WidgetSchema::TRIGGERS, true) ? (string) $trigger['type'] : 'time';
        $value = match ($type) {
            'time' => max(0, min(600, (int) ($trigger['value'] ?? 8))),
            'scroll' => max(1, min(100, (int) ($trigger['value'] ?? 50))),
            'click' => is_string($trigger['value'] ?? null) && $trigger['value'] !== '' ? mb_substr($trigger['value'], 0, 200) : null,
            default => null,
        };
        $pages = is_array($t['pages'] ?? null) ? $t['pages'] : [];
        $utm = [];
        foreach (['source', 'medium', 'campaign'] as $key) {
            $v = $t['utm'][$key] ?? null;
            if (is_string($v) && trim($v) !== '') {
                $utm[$key] = mb_substr(trim($v), 0, 100);
            }
        }
        $countries = array_values(array_unique(array_filter(array_map(
            fn ($c) => is_string($c) && preg_match('/^[A-Za-z]{2}$/', $c) ? strtoupper($c) : null, (array) ($t['countries'] ?? []),
        ))));
        $segments = [];
        foreach ((array) ($t['segments'] ?? []) as $id) {
            if (is_string($id) && Segment::findByPublicId($id) !== null) {
                $segments[] = $id;
            } else {
                $this->error('targeting.segments', __('Uno de los segmentos no existe.'));
            }
        }
        $cart = $t['cart_value_min'] ?? null;
        $cart = is_numeric($cart) && (float) $cart > 0 ? number_format((float) $cart, 2, '.', '') : null;
        $frequency = is_array($t['frequency'] ?? null) ? $t['frequency'] : [];
        $newVisitors = $t['new_visitors'] ?? null;

        return [
            'trigger' => ['type' => $type, 'value' => $value],
            'pages' => ['include' => $this->patterns($pages['include'] ?? []), 'exclude' => $this->patterns($pages['exclude'] ?? [])],
            'page_types' => array_values(array_intersect(WidgetSchema::PAGE_TYPES, (array) ($t['page_types'] ?? []))),
            'devices' => array_values(array_intersect(WidgetSchema::DEVICES, (array) ($t['devices'] ?? []))),
            'new_visitors' => is_bool($newVisitors) ? $newVisitors : null,
            'utm' => $utm,
            'referral' => (bool) ($t['referral'] ?? false),
            'countries' => $countries,
            'segments' => array_values(array_unique($segments)),
            'cart_value_min' => $cart,
            'hide_for_subscribers' => (bool) ($t['hide_for_subscribers'] ?? true),
            'frequency' => [
                'max_views' => max(0, min(50, (int) ($frequency['max_views'] ?? 2))),
                'cooldown_days' => max(0, min(365, (int) ($frequency['cooldown_days'] ?? 7))),
            ],
        ];
    }

    /** @return list<string> */
    private function patterns(mixed $list): array
    {
        $out = [];
        foreach ((array) $list as $p) {
            if (! is_string($p) || trim($p) === '') {
                continue;
            }
            $p = trim($p);
            $path = parse_url($p, PHP_URL_PATH);
            $p = str_starts_with($p, 'http') && is_string($path) ? $path : $p;
            $out[] = mb_substr(str_starts_with($p, '/') ? $p : '/'.$p, 0, 200);
        }

        return array_slice(array_values(array_unique($out)), 0, 50);
    }

    /**
     * @param  array<int|string, mixed>  $variants
     * @return list<array{key: string, weight: int, design: array<string, mixed>, content: array<string, mixed>}>
     */
    private function variants(string $kind, array $variants): array
    {
        $out = [];
        $keys = ['a', 'b', 'c', 'd'];
        foreach (array_values($variants) as $i => $variant) {
            if ($i >= count($keys) || ! is_array($variant)) {
                continue;
            }
            $out[] = [
                'key' => $keys[$i],
                'weight' => max(0, min(100, (int) ($variant['weight'] ?? (int) floor(100 / max(1, count($variants)))))),
                'design' => $this->design($kind, is_array($variant['design'] ?? null) ? $variant['design'] : []),
                'content' => $this->content($kind, is_array($variant['content'] ?? null) ? $variant['content'] : [], $keys[$i]),
            ];
        }
        if ($out === []) {
            $this->error('variants', __('El widget necesita al menos una variante.'));
            $defaults = WidgetSchema::defaults($kind);

            return [['key' => 'a', 'weight' => 100, 'design' => $defaults['design'], 'content' => $defaults['content']]];
        }
        if (array_sum(array_column($out, 'weight')) <= 0) {
            $this->error('variants', __('Los pesos de las variantes deben sumar más de 0.'));
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $d
     * @return array<string, mixed>
     */
    private function design(string $kind, array $d): array
    {
        $positions = WidgetSchema::POSITIONS[$kind] ?? WidgetSchema::POSITIONS['popup'];
        $theme = is_array($d['theme'] ?? null) ? $d['theme'] : [];
        $defaults = ['background' => '#FFFFFF', 'text' => '#111827', 'accent' => '#4F46E5', 'accent_text' => '#FFFFFF'];
        $colors = [];
        foreach ($defaults as $key => $default) {
            $v = $theme[$key] ?? null;
            $colors[$key] = is_string($v) && preg_match('/^#[0-9A-Fa-f]{6}$/', $v) ? strtoupper($v) : $default;
        }
        $image = $d['image_url'] ?? null;
        $image = is_string($image) && preg_match('#^https://#i', $image) ? mb_substr($image, 0, 1000) : null;

        return array_filter([
            'position' => in_array($d['position'] ?? null, $positions, true) ? $d['position'] : $positions[0],
            'layout' => in_array($d['layout'] ?? null, ['image_left', 'image_top', 'text_only'], true) ? $d['layout'] : 'text_only',
            'display' => $kind === 'quiz' ? (($d['display'] ?? 'popup') === 'embedded' ? 'embedded' : 'popup') : null,
            'image_url' => $image,
            'theme' => $colors,
        ], fn ($v) => $v !== null);
    }

    /**
     * @param  array<string, mixed>  $c
     * @return array<string, mixed>
     */
    private function content(string $kind, array $c, string $variant): array
    {
        $out = [
            'title' => $this->text($c['title'] ?? '', 200),
            'body' => $this->text($c['body'] ?? '', 600),
            'fields' => $this->fields($c['fields'] ?? [], "variants.{$variant}.fields"),
            'cta' => $this->text($c['cta'] ?? '', 60),
            'success' => $this->text($c['success'] ?? '', 300),
            'footer' => $this->text($c['footer'] ?? '', 300),
        ];
        if ($kind === 'two_step') {
            $s = is_array($c['step2'] ?? null) ? $c['step2'] : [];
            $out['step2'] = [
                'title' => $this->text($s['title'] ?? '', 200),
                'body' => $this->text($s['body'] ?? '', 600),
                'fields' => $this->fields($s['fields'] ?? [], "variants.{$variant}.step2.fields"),
                'cta' => $this->text($s['cta'] ?? '', 60),
                'success' => $this->text($s['success'] ?? '', 300),
                'skip' => $this->text($s['skip'] ?? '', 60),
            ];
        }
        if ($kind === 'spin_wheel') {
            $out['lose'] = $this->text($c['lose'] ?? '', 300);
        }
        if ($kind === 'bar') {
            // Barra sin campos: el botón lleva a este enlace (https o una ruta del sitio).
            $url = is_string($c['url'] ?? null) ? trim($c['url']) : '';
            $out['url'] = preg_match('#^(https://|/)#i', $url) === 1 ? mb_substr($url, 0, 500) : '';
        }
        if ($kind === 'teaser') {
            $out['teaser'] = $this->text($c['teaser'] ?? '', 60);
        }
        if ($kind === 'quiz') {
            $out['start'] = $this->text($c['start'] ?? '', 60);
            $out['contact_optional'] = (bool) ($c['contact_optional'] ?? false);
        }
        if ($kind === 'wa_widget') {
            $wa = is_array($c['wa'] ?? null) ? $c['wa'] : [];
            $number = preg_replace('/\D+/', '', (string) ($wa['number'] ?? '')) ?? '';
            $out['wa'] = ['number' => mb_substr($number, 0, 20), 'text' => $this->text($wa['text'] ?? '', 300)];
            $out['fields'] = [];
        }

        return $out;
    }

    /** @return list<array{key: string, type: string, required: bool, label: string}> */
    private function fields(mixed $fields, string $path): array
    {
        $out = [];
        foreach ((array) $fields as $field) {
            $key = is_array($field) ? ($field['key'] ?? null) : null;
            if (! is_string($key) || ! isset(WidgetSchema::FIELD_KEYS[$key])) {
                $this->error($path, __('Campo no permitido. Usa correo, WhatsApp, nombre o apellido.'));

                continue;
            }
            if (in_array($key, array_column($out, 'key'), true)) {
                continue;
            }
            $out[] = [
                'key' => $key,
                'type' => WidgetSchema::FIELD_KEYS[$key],
                'required' => (bool) ($field['required'] ?? false),
                'label' => $this->text($field['label'] ?? '', 80),
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $c
     * @return array{channels: list<string>, double_opt_in: bool, template_id: string|null}
     */
    private function consent(string $kind, array $c): array
    {
        $channels = $kind === 'wa_widget' ? [] : array_values(array_intersect(WidgetSchema::CONSENT_CHANNELS, (array) ($c['channels'] ?? [])));
        $template = $c['template_id'] ?? null;
        if (is_string($template) && $template !== '') {
            $model = Template::findByPublicId($template);
            if ($model === null || $model->getAttribute('channel') !== 'email') {
                $this->error('consent_config.template_id', __('La plantilla de confirmación debe ser de correo.'));
                $template = null;
            }
        } else {
            $template = null;
        }

        return ['channels' => $channels, 'double_opt_in' => in_array('email', $channels, true) && (bool) ($c['double_opt_in'] ?? false), 'template_id' => $template];
    }

    /**
     * @param  array<string, mixed>  $c
     * @return array<string, mixed>
     */
    private function gift(array $c): array
    {
        $enabled = (bool) ($c['enabled'] ?? false);

        // Apagado no se valida el monto: queda como borrador para cuando se encienda.
        return ['enabled' => $enabled] + $this->coupon($c, 'coupon_config', $enabled);
    }

    /**
     * @param  array<string, mixed>  $c
     * @return array{kind: string, amount: string, expires_in_days: int, prefix: string, minimum_amount: string|null}
     */
    private function coupon(array $c, string $path, bool $strict = true): array
    {
        $kind = in_array($c['kind'] ?? null, WidgetSchema::COUPON_KINDS, true) ? (string) $c['kind'] : 'percent';
        $amount = is_numeric($c['amount'] ?? null) ? (float) $c['amount'] : 0.0;
        if (! $strict) {
            $amount = $kind === 'percent' ? max(0.0, min(100.0, $amount)) : max(0.0, $amount);
        } elseif ($kind === 'percent' && ($amount <= 0 || $amount > 100)) {
            $this->error($path.'.amount', __('El porcentaje debe estar entre 1 y 100.'));
        } elseif ($kind === 'fixed_cart' && $amount <= 0) {
            $this->error($path.'.amount', __('Escribe el valor del descuento.'));
        }
        $min = $c['minimum_amount'] ?? null;
        $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) ($c['prefix'] ?? '')) ?? '');

        return [
            'kind' => $kind,
            'amount' => number_format($kind === 'free_shipping' ? 0 : $amount, 2, '.', ''),
            'expires_in_days' => max(1, min(365, (int) ($c['expires_in_days'] ?? 7))),
            'prefix' => mb_substr($prefix !== '' ? $prefix : 'IGR', 0, 12),
            'minimum_amount' => is_numeric($min) && (float) $min > 0 ? number_format((float) $min, 2, '.', '') : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $c
     * @return array{prizes: list<array<string, mixed>>}
     */
    private function prizes(array $c): array
    {
        $out = [];
        foreach (array_values((array) ($c['prizes'] ?? [])) as $i => $prize) {
            if (! is_array($prize) || $i >= 12) {
                continue;
            }
            $key = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($prize['key'] ?? ''))) ?: 'p'.($i + 1);
            if (in_array($key, array_column($out, 'key'), true)) {
                $key .= '_'.($i + 1);
            }
            $label = $this->text($prize['label'] ?? '', 40);
            if ($label === '') {
                $this->error("coupon_config.prizes.{$i}.label", __('Cada premio necesita un nombre.'));
            }
            $out[] = [
                'key' => mb_substr($key, 0, 40),
                'label' => $label,
                'weight' => max(0, min(1000, (int) ($prize['weight'] ?? 0))),
                'coupon' => is_array($prize['coupon'] ?? null) ? $this->coupon($prize['coupon'], "coupon_config.prizes.{$i}") : null,
            ];
        }

        return ['prizes' => $out];
    }

    /**
     * @param  array<string, mixed>  $q
     * @return array{questions: list<array<string, mixed>>, outcomes: array<string, mixed>}
     */
    private function quiz(array $q): array
    {
        $questions = [];
        $ids = [];
        foreach (array_values((array) ($q['questions'] ?? [])) as $i => $question) {
            if (! is_array($question) || $i >= 20) {
                continue;
            }
            $id = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($question['id'] ?? ''))) ?: 'q'.($i + 1);
            $ids[] = $id;
            $options = [];
            foreach (array_values((array) ($question['options'] ?? [])) as $j => $option) {
                if (! is_array($option) || $j >= 12) {
                    continue;
                }
                $attributes = [];
                foreach ((array) ($option['attributes'] ?? []) as $k => $v) {
                    if (is_string($k) && preg_match('/^[a-z][a-z0-9_]{0,39}$/', $k) && (is_scalar($v))) {
                        $attributes[$k] = mb_substr((string) $v, 0, 100);
                    }
                }
                $options[] = array_filter([
                    'key' => preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($option['key'] ?? ''))) ?: 'o'.($j + 1),
                    'label' => $this->text($option['label'] ?? '', 120),
                    'next' => is_string($option['next'] ?? null) && $option['next'] !== '' ? $option['next'] : null,
                    'attributes' => $attributes !== [] ? $attributes : null,
                ], fn ($v) => $v !== null);
            }
            if (count($options) < 2) {
                $this->error("quiz.questions.{$i}", __('Cada pregunta necesita al menos 2 opciones.'));
            }
            $attribute = $question['attribute'] ?? null;
            $questions[] = array_filter([
                'id' => $id,
                'text' => $this->text($question['text'] ?? '', 200),
                'attribute' => is_string($attribute) && preg_match('/^[a-z][a-z0-9_]{0,39}$/', $attribute) ? $attribute : null,
                'options' => $options,
            ], fn ($v) => $v !== null);
        }
        // La lógica solo puede saltar a preguntas que existen (o terminar).
        foreach ($questions as $i => $question) {
            foreach ($question['options'] as $option) {
                $next = $option['next'] ?? null;
                if ($next !== null && $next !== 'end' && ! in_array($next, $ids, true)) {
                    $this->error("quiz.questions.{$i}", __('Una opción salta a una pregunta que no existe.'));
                }
            }
        }

        $outcomes = is_array($q['outcomes'] ?? null) ? $q['outcomes'] : [];
        $rules = [];
        foreach (array_values((array) ($outcomes['rules'] ?? [])) as $i => $rule) {
            if (! is_array($rule) || $i >= 30) {
                continue;
            }
            $match = [];
            foreach ((array) ($rule['match'] ?? []) as $qid => $keys) {
                if (in_array($qid, $ids, true)) {
                    $match[(string) $qid] = array_values(array_filter((array) $keys, 'is_string'));
                }
            }
            $rules[] = [
                'id' => 'r'.($i + 1),
                'label' => $this->text($rule['label'] ?? '', 120),
                'match' => $match,
                'products' => $this->productRule(is_array($rule['products'] ?? null) ? $rule['products'] : []),
                'message' => $this->text($rule['message'] ?? '', 300),
            ];
        }
        $fallback = is_array($outcomes['fallback'] ?? null) ? $outcomes['fallback'] : [];

        return [
            'questions' => $questions,
            'outcomes' => [
                'rules' => $rules,
                'fallback' => ['products' => $this->productRule(is_array($fallback['products'] ?? null) ? $fallback['products'] : ['kind' => 'trending']), 'message' => $this->text($fallback['message'] ?? '', 300)],
                'limit' => max(1, min(12, (int) ($outcomes['limit'] ?? 4))),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    private function productRule(array $p): array
    {
        $ids = fn (mixed $list) => array_values(array_slice(array_filter(array_map(fn ($v) => is_scalar($v) ? mb_substr((string) $v, 0, 64) : null, (array) $list)), 0, 50));
        $kind = $p['kind'] ?? null;

        return [
            'product_ids' => $ids($p['product_ids'] ?? []),
            'category_ids' => $ids($p['category_ids'] ?? []),
            'brands' => array_values(array_slice(array_filter(array_map(fn ($v) => is_string($v) ? mb_substr(trim($v), 0, 120) : null, (array) ($p['brands'] ?? []))), 0, 20)),
            'max_price' => is_numeric($p['max_price'] ?? null) && (float) $p['max_price'] > 0 ? number_format((float) $p['max_price'], 2, '.', '') : null,
            'kind' => in_array($kind, ['trending', 'best_sellers_category', 'para_ti'], true) ? $kind : null,
        ];
    }

    private function date(string $key, mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse((string) $value)->utc();
        } catch (\Throwable) {
            $this->error($key, __('La fecha no es válida.'));

            return null;
        }
    }

    private function text(mixed $value, int $max): string
    {
        return is_string($value) ? mb_substr(trim(strip_tags($value)), 0, $max) : '';
    }

    private function error(string $key, string $message): void
    {
        $this->errors[$key][] = $message;
    }

    private function throwIfErrors(): void
    {
        if ($this->errors !== []) {
            $errors = array_map(fn (array $m) => array_values(array_unique($m)), $this->errors);
            $this->errors = [];

            throw ValidationException::withMessages($errors);
        }
    }
}
