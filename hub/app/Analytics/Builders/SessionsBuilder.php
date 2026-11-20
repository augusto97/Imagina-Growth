<?php

declare(strict_types=1);

namespace App\Analytics\Builders;

use App\Analytics\Ch;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Arma la tabla `sessions` de ClickHouse desde los eventos (hub/CLAUDE.md §15:
 * cada 10 minutos). Una sesión se cierra tras 30 minutos sin actividad; solo
 * se escriben sesiones cerradas. Reconstruir una sesión es idempotente
 * (ReplacingMergeTree por started_at + session_id, se lee con FINAL).
 *
 * R7 de la auditoría: la ventana del programador (180 minutos) vuelve a ver
 * cada sesión unas 15 veces. Solo se inserta la sesión nueva o la que cambió
 * (llegó un evento tarde, la persona se identificó): las que ya están con las
 * mismas columnas (misma huella cityHash64, con los mismos tipos de los dos
 * lados) se descartan con un NOT IN contra `sessions`. Sin FINAL: basta con que
 * alguna versión guardada sea igual, porque una sesión solo crece (más eventos,
 * contacto identificado).
 *
 * El contacto de la sesión sale de identity_map (anónimo → contacto), así una
 * visita anónima queda atribuida cuando la persona se identifica después.
 */
final class SessionsBuilder
{
    public function build(Workspace $workspace, CarbonInterface $since, ?CarbonInterface $until = null): void
    {
        $until ??= CarbonImmutable::now()->subMinutes(30);
        $sql = <<<'SQL'
            INSERT INTO sessions (workspace_id, site_id, session_id, anonymous_id, contact_id, started_at, ended_at, pageviews,
                landing_path, exit_path, channel, utm_source, utm_medium, utm_campaign, device_type, browser, city, region, country,
                viewed_product, added_to_cart, started_checkout, searched, cta_clicks, purchased, orders, is_returning, revenue)
            SELECT
                n.workspace_id, n.site_id, n.session_id, n.anonymous_id, n.contact_id, n.started_at, n.ended_at, n.pageviews,
                n.landing_path, n.exit_path, n.channel, n.utm_source, n.utm_medium, n.utm_campaign, n.device_type, n.browser, n.city,
                n.region, n.country, n.viewed_product, n.added_to_cart, n.started_checkout, n.searched, n.cta_clicks, n.purchased,
                n.orders, n.is_returning, n.revenue
            FROM (
            SELECT
                s.workspace_id AS workspace_id, s.site_id AS site_id, s.session_id AS session_id, s.anonymous_id AS anonymous_id,
                if(s.contact_id != '', s.contact_id, ifNull(im.contact_id, '')) AS contact_id,
                s.started_at AS started_at, s.ended_at AS ended_at, s.pageviews AS pageviews, s.landing_path AS landing_path,
                s.exit_path AS exit_path, toString(s.channel) AS channel, toString(s.utm_source) AS utm_source,
                toString(s.utm_medium) AS utm_medium, s.utm_campaign AS utm_campaign, toString(s.device_type) AS device_type,
                toString(s.browser) AS browser, toString(s.city) AS city, toString(s.region) AS region, toString(s.country) AS country,
                s.viewed_product AS viewed_product, s.added_to_cart AS added_to_cart, s.started_checkout AS started_checkout,
                s.searched AS searched, s.cta_clicks AS cta_clicks, s.purchased AS purchased, s.orders AS orders,
                s.is_returning AS is_returning, toDecimal64(s.revenue, 2) AS revenue
            FROM (
                SELECT
                    workspace_id,
                    any(site_id) AS site_id,
                    session_id,
                    argMinIf(anonymous_id, occurred_at, anonymous_id != '') AS anonymous_id,
                    argMaxIf(contact_id, occurred_at, contact_id != '') AS contact_id,
                    min(occurred_at) AS started_at,
                    max(occurred_at) AS ended_at,
                    toUInt16(least(countIf(event = 'page.viewed'), 65535)) AS pageviews,
                    argMinIf(path, occurred_at, event = 'page.viewed') AS landing_path,
                    argMaxIf(path, occurred_at, event = 'page.viewed') AS exit_path,
                    if(argMinIf(channel, occurred_at, channel != '') = '', 'direct', argMinIf(channel, occurred_at, channel != '')) AS channel,
                    argMinIf(utm_source, occurred_at, utm_source != '') AS utm_source,
                    argMinIf(utm_medium, occurred_at, utm_medium != '') AS utm_medium,
                    argMinIf(utm_campaign, occurred_at, utm_campaign != '') AS utm_campaign,
                    argMinIf(device_type, occurred_at, device_type != '') AS device_type,
                    argMinIf(browser, occurred_at, browser != '') AS browser,
                    argMinIf(city, occurred_at, city != '') AS city,
                    argMinIf(region, occurred_at, region != '') AS region,
                    argMinIf(country, occurred_at, country != '') AS country,
                    toUInt8(countIf(event = 'product.viewed') > 0) AS viewed_product,
                    toUInt8(countIf(event = 'cart.item_added') > 0) AS added_to_cart,
                    toUInt8(countIf(event = 'checkout.started') > 0) AS started_checkout,
                    toUInt8(countIf(event = 'search.performed') > 0) AS searched,
                    toUInt16(least(countIf(event = 'cta.clicked'), 65535)) AS cta_clicks,
                    toUInt8(countIf(event = 'order.created') > 0) AS purchased,
                    toUInt16(uniqExactIf(order_id, event = 'order.created')) AS orders,
                    toUInt8(0) AS is_returning,
                    sumIf(revenue, event = 'order.created') AS revenue
                FROM events
                WHERE workspace_id = {workspace_id:UInt32}
                  AND session_id != ''
                  AND occurred_at >= {from:DateTime64(3)} - INTERVAL 1 DAY
                  AND session_id IN (
                      SELECT DISTINCT session_id FROM events
                      WHERE workspace_id = {workspace_id:UInt32} AND session_id != ''
                        AND occurred_at >= {from:DateTime64(3)} AND occurred_at < {until:DateTime64(3)}
                  )
                GROUP BY workspace_id, session_id
                HAVING max(occurred_at) < {until:DateTime64(3)} AND countIf(source_type = 'browser') > 0
            ) AS s
            LEFT JOIN (
                SELECT anonymous_id, argMax(contact_id, linked_at) AS contact_id
                FROM identity_map WHERE workspace_id = {workspace_id:UInt32}
                GROUP BY anonymous_id
            ) AS im ON im.anonymous_id = s.anonymous_id
            ) AS n
            LEFT ANTI JOIN (
                SELECT session_id, cityHash64(anonymous_id, contact_id, started_at, ended_at, pageviews, landing_path, exit_path,
                    toString(channel), toString(utm_source), toString(utm_medium), utm_campaign, toString(device_type),
                    toString(browser), toString(city), toString(region), toString(country), viewed_product, added_to_cart,
                    started_checkout, searched, cta_clicks, purchased, orders, is_returning, revenue) AS fp
                FROM sessions
                WHERE workspace_id = {workspace_id:UInt32} AND started_at >= {from:DateTime64(3)} - INTERVAL 1 DAY
            ) AS e ON e.session_id = n.session_id AND e.fp = cityHash64(n.anonymous_id, n.contact_id, n.started_at, n.ended_at,
                n.pageviews, n.landing_path, n.exit_path, n.channel, n.utm_source, n.utm_medium, n.utm_campaign, n.device_type,
                n.browser, n.city, n.region, n.country, n.viewed_product, n.added_to_cart, n.started_checkout, n.searched,
                n.cta_clicks, n.purchased, n.orders, n.is_returning, n.revenue)
            SQL;

        Ch::forWorkspace($workspace)->execute($sql, [
            'from' => $since->utc()->format('Y-m-d H:i:s.v'),
            'until' => $until->utc()->format('Y-m-d H:i:s.v'),
        ], ['join_use_nulls' => 1]);
    }
}
