<?php

/*
 * Lado del hub de la prueba de la Fase 3 (infra/e2e/fase3.sh). Subcomandos:
 *
 *   php fase3-hub.php setup                    → dueña con 2FA, espacio de trabajo, correo por SMTP (buzón local) y,
 *                                                 como lo configuraría la comerciante en el panel: popup de bienvenida
 *                                                 (WhatsApp + 10%), bienvenida de referido (correo + regalo del amigo),
 *                                                 fidelización (1 punto por $1.000, sin días de devolución), referidos
 *                                                 (10% al amigo, 500 puntos a quien refiere, sin días de devolución) y
 *                                                 reseñas (correo el mismo día de la entrega, publicación automática).
 *                                                 Imprime: ws_… código
 *   php fase3-hub.php reloj ws_… correo        → zona horaria de la clienta donde la ventana legal está abierta ahora
 *   php fase3-hub.php estado ws_… correo       → resumen de una línea (puntos, reseñas, referidos, mensajes)
 *   php fase3-hub.php aprobar ws_…             → la comerciante aprueba los referidos en revisión manual (en la
 *                                                 prueba, Marta y Luis comparten IP: es la señal de fraude esperada)
 *   php fase3-hub.php check ws_… correo correoAmigo pedido pedidoAmigo → comprobaciones finales (código 1 si algo falla)
 *
 * Nada de datos personales en la salida: solo identificadores, conteos y montos.
 */

declare(strict_types=1);

use App\Analytics\Ch;
use App\Domain\Commerce\Models\CouponIssued;
use App\Domain\Commerce\Models\Order;
use App\Domain\Consent\Policy\LegalWindow;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Contacts\Queries\ProfileQuery;
use App\Domain\Loyalty\Ledger\DatabaseLoyaltyLedger;
use App\Domain\Loyalty\Models\LoyaltyProgram;
use App\Domain\Loyalty\Support\ProgramRules;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Onsite\Models\OnsiteWidget;
use App\Domain\Onsite\Support\WidgetSchema;
use App\Domain\Onsite\Support\WidgetValidator;
use App\Domain\Referrals\Actions\RewardReferrer;
use App\Domain\Referrals\Models\Referral;
use App\Domain\Referrals\Models\ReferralProgram;
use App\Domain\Referrals\Support\ProgramSettings;
use App\Domain\Referrals\Support\ReferralSwitch;
use App\Domain\Reviews\Models\Review;
use App\Domain\Reviews\Support\ReviewSettings;
use App\Domain\Sites\Actions\CreateConnectCode;
use App\Domain\Workspaces\Actions\CreateWorkspace;
use App\Domain\Workspaces\Data\WorkspaceData;
use App\Domain\Workspaces\Models\User;
use App\Support\Console\WorkspaceOption;
use App\Support\Pii\BlindIndex;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$hub = dirname(__DIR__, 2).'/hub';
require $hub.'/vendor/autoload.php';
$app = require $hub.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$cmd = $argv[1] ?? '';
$current = app(CurrentWorkspace::class);
$contactFor = function (string $email, $ws): ?Contact {
    return Contact::query()->where('email_bidx', BlindIndex::email($ws->id, $email))->whereNull('merged_into_id')->first();
};

if ($cmd === 'setup') {
    $owner = User::create(['name' => 'Dueña F3', 'email' => getenv('OWNER_EMAIL') ?: 'duena.f3@tienda.test', 'password' => getenv('OWNER_PASS') ?: 'Clave-f3-2026']);
    $owner->forceFill(['two_factor_secret' => encrypt(getenv('TOTP_SECRET') ?: 'JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()])->save();
    $ws = app(CreateWorkspace::class)->handle($owner, new WorkspaceData(name: 'Pinturas La 14'));
    $current->run($ws, function () use ($ws, $owner) {
        $settings = $ws->settings ?? [];
        $settings['business_profile'] = ['name' => 'Pinturas y Ferretería La 14', 'address' => 'Calle 23 # 21-41, Manizales, Caldas', 'contact' => 'hola@pinturasla14.test · 606 884 0000', 'whatsapp' => '573001234567', 'logo_url' => ''];
        $ws->forceFill(['settings' => $settings])->save();
        ChannelAccount::query()->create([
            'workspace_id' => $ws->id, 'channel' => 'email', 'provider' => 'smtp', 'display_name' => 'Correo de la tienda',
            'identity' => 'pinturasla14.test', 'status' => 'active', 'is_default' => true,
            'config' => ['host' => '127.0.0.1', 'port' => (int) (getenv('SMTP_PORT') ?: 2526), 'from_email' => 'hola@pinturasla14.test', 'from_name' => 'Pinturas La 14'],
        ]);

        // Captación (M12): el popup por defecto (nombre, WhatsApp con su casilla, 10%) al entrar al inicio, y la
        // bienvenida de referido con correo, por delante (prioridad menor) para quien llega con ?ref=.
        $widget = function (string $kind, string $name, int $priority, array $patch) use ($ws, $owner): OnsiteWidget {
            $d = WidgetSchema::defaults($kind);
            $content = array_replace($d['content'], $patch['content'] ?? []);
            $model = OnsiteWidget::query()->create([
                'workspace_id' => $ws->id, 'site_id' => null, 'name' => $name, 'kind' => $kind, 'priority' => $priority,
                'targeting' => array_replace($d['targeting'], $patch['targeting'] ?? []),
                'design' => $d['design'], 'content' => $content,
                'variants' => [['key' => 'a', 'weight' => 100, 'design' => $d['design'], 'content' => $content]],
                'consent_config' => array_replace($d['consent_config'], $patch['consent'] ?? []),
                'coupon_config' => array_replace($d['coupon_config'], $patch['coupon'] ?? []),
                'created_by' => $owner->id, 'status' => 'draft',
            ]);
            app(WidgetValidator::class)->assertActivatable($model);
            $model->forceFill(['status' => 'active'])->save();

            return $model;
        };
        $widget('popup', 'Bienvenida 10% por WhatsApp', 50, [
            'targeting' => ['trigger' => ['type' => 'immediate', 'value' => null], 'page_types' => ['home']],
        ]);
        $email = ['key' => 'email', 'type' => 'email', 'required' => true, 'label' => __('Tu correo')];
        $name = ['key' => 'first_name', 'type' => 'text', 'required' => false, 'label' => __('Tu nombre')];
        $widget('referral_welcome', 'Regalo del amigo', 10, [
            'targeting' => ['trigger' => ['type' => 'immediate', 'value' => null]],
            'content' => ['body' => 'Déjanos tu correo y te enviamos el regalo para tu primera compra.', 'fields' => [$name, $email]],
            'consent' => ['channels' => ['email']],
        ]);

        // Fidelización (M13): 1 punto por $1.000, sin días de devolución para la prueba (por defecto, 15).
        $d = ProgramRules::defaults();
        LoyaltyProgram::query()->create([
            'workspace_id' => $ws->id, 'status' => 'active', 'currency_name' => 'Puntos La 14', 'earn_rules' => $d['earn_rules'],
            'redeem_rules' => $d['redeem_rules'], 'expiry_policy' => $d['expiry_policy'], 'pending_days' => 0, 'wallet_rules' => $d['wallet_rules'],
        ]);
        app(DatabaseLoyaltyLedger::class)->forgetProgram();

        // Referidos (M14): 10% al amigo, 500 puntos a quien refiere, califica al completar sin días de devolución.
        ReferralProgram::query()->create([
            'workspace_id' => $ws->id, 'status' => 'active',
            'friend_reward' => ['kind' => 'percent', 'amount' => '10.00', 'expires_days' => 30],
            'referrer_reward' => ['type' => 'points', 'points' => 500],
            'rules' => ['return_days' => 0] + ProgramSettings::defaultRules(),
            'share_text' => null,
        ]);
        ReferralSwitch::forget($ws->id);

        // Reseñas (M15): por correo el mismo día de la entrega, publicación automática y en WooCommerce.
        $reviews = ReviewSettings::defaults();
        $reviews['request'] = array_replace($reviews['request'], ['enabled' => true, 'days' => 0, 'channels' => ['email']]);
        $reviews['moderation'] = ['mode' => 'auto'];
        $reviews['google_review_url'] = 'https://g.page/r/pinturas-la-14/review';
        ReviewSettings::save($ws, $reviews);
    });
    $code = app(CreateConnectCode::class)->handle($ws, $owner, [])['code'];
    echo $ws->publicId(), ' ', $code, "\n";
    exit(0);
}

$ws = WorkspaceOption::resolve($argv[2] ?? '')->firstOrFail();

exit($current->run($ws, function () use ($cmd, $ws, $argv, $contactFor): int {
    $email = $argv[3] ?? '';
    switch ($cmd) {
        case 'reloj':
            $contact = $contactFor($email, $ws) ?? throw new RuntimeException('No existe la clienta');
            $now = CarbonImmutable::now();
            foreach (['America/Bogota', 'Asia/Tokyo', 'Europe/Madrid', 'Asia/Kolkata', 'Pacific/Auckland', 'America/Los_Angeles', 'Asia/Dubai'] as $tz) {
                if (LegalWindow::isOpen('CO', $now, $tz) && LegalWindow::isOpen('CO', $now->addHour(), $tz)) {
                    $contact->forceFill(['country' => 'CO', 'timezone' => $tz])->save();
                    echo $tz, "\n";

                    return 0;
                }
            }
            fwrite(STDERR, "No hay ninguna zona con la ventana abierta ahora\n");

            return 1;

        case 'estado':
            $contact = $contactFor($email, $ws);
            echo json_encode($contact === null ? ['contacto' => null] : [
                'puntos' => (int) $contact->points_balance,
                'libro' => DB::table('loyalty_ledger')->where('contact_id', $contact->id)->orderBy('created_at')->get(['reason', 'delta', 'status'])
                    ->map(fn ($r) => $r->reason.':'.(int) $r->delta.':'.$r->status)->all(),
                'reseñas' => Review::query()->where('contact_id', $contact->id)->get(['status', 'sync_status'])->map(fn ($r) => $r->status.'/'.$r->sync_status)->all(),
                'solicitudes' => DB::table('review_requests')->where('contact_id', $contact->id)->pluck('status')->all(),
                'referidos' => Referral::query()->where('referrer_contact_id', $contact->id)->get(['status', 'review_status'])->map(fn ($r) => $r->status.'/'.$r->review_status)->all(),
                'mensajes' => DB::table('messages')->where('contact_id', $contact->id)->get(['channel', 'status', 'source_type', 'policy_reason'])
                    ->map(fn ($m) => $m->source_type.':'.$m->channel.':'.$m->status.($m->policy_reason ? '('.$m->policy_reason.')' : ''))->all(),
            ], JSON_UNESCAPED_UNICODE), "\n";

            return 0;

        case 'aprobar':
            $n = 0;
            foreach (Referral::query()->where('review_status', Referral::PENDING_REVIEW)->get() as $referral) {
                $referral->forceFill(['review_status' => 'approved'])->save();
                if ($referral->status === Referral::QUALIFIED) {
                    app(RewardReferrer::class)->handle($referral);
                }
                $n++;
            }
            echo "aprobados={$n}\n";

            return 0;

        case 'check':
            return fase3Check($ws, $contactFor($email, $ws), $contactFor($argv[4] ?? '', $ws), (string) ($argv[5] ?? ''), (string) ($argv[6] ?? ''));
    }
    fwrite(STDERR, "Subcomando desconocido\n");

    return 2;
}));

function fase3Check($ws, ?Contact $marta, ?Contact $luis, string $orderM, string $orderL): int
{
    $failures = 0;
    $check = function (string $label, bool $ok, string $detail = '') use (&$failures): void {
        printf("%s %s%s\n", $ok ? '✓' : '✗', $label, $detail !== '' ? " — {$detail}" : '');
        $failures += $ok ? 0 : 1;
    };
    $check('Marta y Luis existen (índice ciego del correo)', $marta !== null && $luis !== null);
    if ($marta === null || $luis === null) {
        return 1;
    }
    $popup = OnsiteWidget::query()->where('kind', 'popup')->firstOrFail();
    $welcome = OnsiteWidget::query()->where('kind', 'referral_welcome')->firstOrFail();

    // 1. Captación: dejó su WhatsApp en el popup, con consentimiento y cupón.
    $capture = DB::table('widget_captures')->where('widget_id', $popup->id)->where('contact_id', $marta->id)->first();
    $check('Marta dejó sus datos en el popup (widget_captures)', $capture !== null && $capture->coupon_id !== null, $capture !== null ? 'variante '.$capture->variant.($capture->was_known ? ', ya conocida' : ', nueva') : '—');
    $wa = DB::table('contact_consents')->where('contact_id', $marta->id)->where('channel', 'whatsapp')->first();
    $check('… con consentimiento de WhatsApp concedido desde el widget', $wa !== null && $wa->status === 'granted' && str_starts_with((string) $wa->source, 'widget'), $wa !== null ? $wa->status.' · '.$wa->source.' · '.$wa->legal_text_version : '—');
    $coupon = $capture !== null ? CouponIssued::query()->find($capture->coupon_id) : null;

    // 2. Compra con el cupón del popup, atribuida al widget.
    $order = Order::query()->where('external_id', $orderM)->first();
    $check('El pedido de Marta está en el hub, pagado y completado', $order !== null && (bool) $order->is_paid && $order->status === 'completed', 'pedido #'.$orderM.' · '.($order->status ?? '—'));
    $check('… es de Marta (misma persona del popup y del checkout)', $order !== null && $order->contact_id === $marta->id);
    $check('… pagado con el cupón del popup', $coupon !== null && $order !== null && $coupon->redeemed_order_id === $order->id, ($coupon->code ?? '—').' · '.($coupon->status ?? '—'));
    $check('… atribuido al popup (paso 4 de la atribución)', $order !== null && $order->attributed_widget_id === $popup->id, (string) ($order->attributed_widget_id ?? '—').' / variante '.($order->attributed_widget_variant ?? '—'));

    // 3. Puntos: pedido, reseña con foto y referido, todos confirmados; el saldo cuadra.
    $ledger = DB::table('loyalty_ledger')->where('contact_id', $marta->id)->orderBy('created_at')->get();
    $confirmed = $ledger->where('status', 'confirmed')->where('kind', 'points');
    $byReason = [];
    foreach ($confirmed as $row) {
        $byReason[$row->reason] = ($byReason[$row->reason] ?? 0) + (int) $row->delta;
    }
    $expectedOrder = (int) floor((float) ($order->subtotal ?? 0) / 1000);
    $check('Puntos del pedido confirmados', ($byReason['order'] ?? 0) > 0, ($byReason['order'] ?? 0).' puntos (subtotal '.($order->subtotal ?? '—').')');
    $check('Puntos de la reseña con foto confirmados', ($byReason['review_media'] ?? 0) > 0 || ($byReason['review'] ?? 0) > 0, json_encode($byReason) ?: '');
    $check('Puntos por referir a Luis confirmados', ($byReason['referral'] ?? 0) === 500, (string) ($byReason['referral'] ?? 0));
    $balance = array_sum(array_map('intval', $confirmed->pluck('delta')->all()));
    $check('El saldo de puntos cuadra con el libro', (int) $marta->fresh()->points_balance === $balance, 'saldo '.$marta->fresh()->points_balance.' = libro '.$balance);
    $check('Nada pendiente en el libro de Marta', $ledger->where('status', 'pending')->filter(fn ($r) => ! $ledger->contains(fn ($c) => $c->parent_id === $r->id))->isEmpty());

    // 4. Reseña con foto: publicada en el hub y en WooCommerce.
    $review = Review::query()->where('contact_id', $marta->id)->first();
    $media = (array) ($review->media ?? []);
    $check('Marta dejó una reseña de 5 estrellas con foto', $review !== null && (int) $review->rating === 5 && count($media) >= 1, count($media).' archivo(s)');
    $check('… publicada (moderación automática) y sincronizada con la tienda', $review !== null && $review->status === 'published' && $review->external_review_id !== null && $review->sync_status === 'synced', ($review->status ?? '—').' · '.($review->sync_status ?? '—').' · comentario '.($review->external_review_id ?? '—'));
    $request = DB::table('review_requests')->where('contact_id', $marta->id)->first();
    $check('La solicitud de reseña se envió por correo y quedó completada', $request !== null && $request->status === 'completed' && $request->channel === 'email', ($request->status ?? '—').' · '.($request->channel ?? '—'));

    // 5. Referido: Luis llegó con el enlace, recibió su regalo, compró y Marta fue recompensada.
    $referral = Referral::query()->where('referrer_contact_id', $marta->id)->first();
    $orderLuis = Order::query()->where('external_id', $orderL)->first();
    $check('Luis quedó como referido de Marta y la recompensa se entregó', $referral !== null && $referral->friend_contact_id === $luis->id && $referral->status === Referral::REWARDED, ($referral->status ?? '—').' · revisión '.($referral->review_status ?? '—').' · señales '.json_encode($referral?->signals() ?? []));
    $friendCoupon = $referral !== null && $referral->friend_coupon_id !== null ? CouponIssued::query()->find($referral->friend_coupon_id) : null;
    $check('… su regalo lo dio la bienvenida de referido y lo usó al comprar', $friendCoupon !== null && $orderLuis !== null && $friendCoupon->redeemed_order_id === $orderLuis->id, ($friendCoupon->code ?? '—'));
    $luisCapture = DB::table('widget_captures')->where('widget_id', $welcome->id)->where('contact_id', $luis->id)->exists();
    $check('… y Luis dejó su correo en la bienvenida de referido', $luisCapture);
    $check('El pedido de Luis está atribuido al referido', $orderLuis !== null && $referral !== null && $orderLuis->attributed_referral_id === $referral->id, (string) ($orderLuis->attributed_referral_id ?? '—'));
    $check('… con el canal de afiliados y referidos', $orderLuis !== null && in_array('affiliates', [$orderLuis->channel_last, $orderLuis->channel_first], true), ($orderLuis->channel_first ?? '—').' → '.($orderLuis->channel_last ?? '—'));

    // 6. Todo en el perfil de Marta (línea de tiempo del perfil 360).
    $timeline = app(ProfileQuery::class)->timeline($ws, $marta, [], null, 200);
    $events = array_count_values(array_column($timeline['data'], 'event'));
    foreach (['widget.submitted', 'order.paid', 'order.completed', 'points.earned', 'review.requested', 'review.collected', 'referral.converted'] as $e) {
        $check("En la línea de tiempo de Marta: {$e}", ($events[$e] ?? 0) >= 1, (string) ($events[$e] ?? 0));
    }
    $ch = Ch::forWorkspace($ws);
    $rejections = DB::table('ingest_rejections')->where('workspace_id', $ws->id)->get(['event', 'reason', 'detail']);
    $check('Ningún evento rechazado en la ingesta', $rejections->isEmpty(), $rejections->map(fn ($r) => $r->event.':'.$r->reason.':'.mb_substr((string) $r->detail, 0, 80))->implode(' | '));
    $refCode = $ch->value("SELECT count() FROM events WHERE workspace_id = {workspace_id:UInt32} AND event = 'order.paid' AND order_id = {o:String} AND channel = 'affiliates'", ['o' => $orderL]);
    $check('El order.paid de Luis llegó a ClickHouse con el canal de referidos', (int) $refCode >= 1, (string) $refCode);

    echo $failures === 0 ? "\nComprobaciones de la Fase 3: todo en verde.\n" : "\nComprobaciones de la Fase 3: {$failures} fallaron.\n";

    return $failures === 0 ? 0 : 1;
}
