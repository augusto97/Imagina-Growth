<?php

use App\Ingest\Enrichment\ChannelClassifier;

it('agrupa canales según inteligencia.md §2', function (array $touch, string $expected) {
    expect((new ChannelClassifier)->classify($touch + ['site_host' => 'tienda.co']))->toBe($expected);
})->with([
    'anuncio clic a WhatsApp' => [['ctwa_clid' => 'abc'], 'ctwa'],
    'enlace de mensaje igr' => [['link_token' => 'lt_1', 'utm' => ['medium' => 'whatsapp']], 'igr_whatsapp'],
    'correo igr' => [['utm' => ['source' => 'igr', 'medium' => 'email']], 'igr_email'],
    'pago Meta con fbclid' => [['click_ids' => ['fbclid' => 'x'], 'utm' => ['medium' => 'paid_social']], 'paid_meta'],
    'pago Meta por source' => [['utm' => ['source' => 'instagram', 'medium' => 'cpc']], 'paid_meta'],
    'fbclid sin medio pago es social' => [['click_ids' => ['fbclid' => 'x'], 'referrer' => 'https://l.facebook.com/'], 'organic_social'],
    'pago Google gclid' => [['click_ids' => ['gclid' => 'x']], 'paid_google'],
    'pago Google wbraid' => [['click_ids' => ['wbraid' => 'x']], 'paid_google'],
    'pago TikTok' => [['click_ids' => ['ttclid' => 'x']], 'paid_tiktok'],
    'otros pagos' => [['utm' => ['source' => 'bing', 'medium' => 'display']], 'paid_other'],
    'correo otros' => [['utm' => ['medium' => 'email', 'source' => 'mailchimp']], 'email'],
    'webmail' => [['referrer' => 'https://mail.google.com/mail/u/0/'], 'email'],
    'WhatsApp orgánico' => [['referrer' => 'https://l.wl.co/l?u=x'], 'whatsapp'],
    'asistente de IA' => [['referrer' => 'https://chatgpt.com/'], 'ai_assistants'],
    'IA por utm' => [['utm' => ['source' => 'perplexity']], 'ai_assistants'],
    'búsqueda orgánica' => [['referrer' => 'https://www.google.com.co/'], 'organic_search'],
    'bing' => [['referrer' => 'https://www.bing.com/search?q=x'], 'organic_search'],
    'social orgánico' => [['referrer' => 'https://t.co/abc'], 'organic_social'],
    'referido' => [['referrer' => 'https://blogdepinturas.com/post'], 'referral'],
    'navegación interna es directo' => [['referrer' => 'https://www.tienda.co/producto/x'], 'direct'],
    'directo' => [[], 'direct'],
]);
