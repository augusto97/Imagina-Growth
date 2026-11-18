<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Insights;

use App\Domain\Intelligence\Insights\Detectors\CartAbandonmentProductsDetector;
use App\Domain\Intelligence\Insights\Detectors\ChannelLtvDetector;
use App\Domain\Intelligence\Insights\Detectors\CityPotentialDetector;
use App\Domain\Intelligence\Insights\Detectors\ConcentrationDetector;
use App\Domain\Intelligence\Insights\Detectors\ConversionDropDetector;
use App\Domain\Intelligence\Insights\Detectors\ConversionsFailingDetector;
use App\Domain\Intelligence\Insights\Detectors\DataDriftDetector;
use App\Domain\Intelligence\Insights\Detectors\DiscountDependencyDetector;
use App\Domain\Intelligence\Insights\Detectors\EmailReputationDetector;
use App\Domain\Intelligence\Insights\Detectors\EntryProductDetector;
use App\Domain\Intelligence\Insights\Detectors\EssentialFlowInactiveDetector;
use App\Domain\Intelligence\Insights\Detectors\FatigueDetector;
use App\Domain\Intelligence\Insights\Detectors\FirstPurchaseStalledDetector;
use App\Domain\Intelligence\Insights\Detectors\FormLowConversionDetector;
use App\Domain\Intelligence\Insights\Detectors\LeadSourceCloseDetector;
use App\Domain\Intelligence\Insights\Detectors\LeadsUnansweredDetector;
use App\Domain\Intelligence\Insights\Detectors\LowStockStarsDetector;
use App\Domain\Intelligence\Insights\Detectors\PaymentMethodDetector;
use App\Domain\Intelligence\Insights\Detectors\SearchNoResultsDetector;
use App\Domain\Intelligence\Insights\Detectors\SlowPagesDetector;
use App\Domain\Intelligence\Insights\Detectors\ValuableCustomersAtRiskDetector;
use App\Domain\Intelligence\Insights\Detectors\ViewedNotBoughtDetector;
use App\Domain\Intelligence\Insights\Detectors\WhatsappQualityDetector;

/**
 * Los 23 detectores de inteligencia.md §5.1 con su número, categoría y cadencia.
 * Los de servicios (21 a 23) llegaron en la Fase 5 con los leads (M18).
 */
final class DetectorCatalog
{
    public const CATEGORIES = ['sales', 'customers', 'messaging', 'measurement', 'services'];

    /** @var list<array{number: int, key: string, category: string, cadence: 'nightly'|'weekly', class: class-string<Detector>|null, phase: int}> */
    private const ENTRIES = [
        ['number' => 1, 'key' => 'conversion_drop', 'category' => 'sales', 'cadence' => 'nightly', 'class' => ConversionDropDetector::class, 'phase' => 1],
        ['number' => 2, 'key' => 'payment_method', 'category' => 'sales', 'cadence' => 'nightly', 'class' => PaymentMethodDetector::class, 'phase' => 1],
        ['number' => 3, 'key' => 'viewed_not_bought', 'category' => 'sales', 'cadence' => 'weekly', 'class' => ViewedNotBoughtDetector::class, 'phase' => 4],
        ['number' => 4, 'key' => 'cart_abandonment_products', 'category' => 'sales', 'cadence' => 'weekly', 'class' => CartAbandonmentProductsDetector::class, 'phase' => 4],
        ['number' => 5, 'key' => 'search_no_results', 'category' => 'sales', 'cadence' => 'weekly', 'class' => SearchNoResultsDetector::class, 'phase' => 4],
        ['number' => 6, 'key' => 'low_stock_stars', 'category' => 'sales', 'cadence' => 'nightly', 'class' => LowStockStarsDetector::class, 'phase' => 1],
        ['number' => 7, 'key' => 'slow_pages', 'category' => 'sales', 'cadence' => 'weekly', 'class' => SlowPagesDetector::class, 'phase' => 4],
        ['number' => 8, 'key' => 'valuable_at_risk', 'category' => 'customers', 'cadence' => 'nightly', 'class' => ValuableCustomersAtRiskDetector::class, 'phase' => 1],
        ['number' => 9, 'key' => 'first_purchase_stalled', 'category' => 'customers', 'cadence' => 'nightly', 'class' => FirstPurchaseStalledDetector::class, 'phase' => 4],
        ['number' => 10, 'key' => 'concentration', 'category' => 'customers', 'cadence' => 'weekly', 'class' => ConcentrationDetector::class, 'phase' => 4],
        ['number' => 11, 'key' => 'channel_ltv', 'category' => 'customers', 'cadence' => 'weekly', 'class' => ChannelLtvDetector::class, 'phase' => 4],
        ['number' => 12, 'key' => 'entry_product', 'category' => 'customers', 'cadence' => 'weekly', 'class' => EntryProductDetector::class, 'phase' => 4],
        ['number' => 13, 'key' => 'city_potential', 'category' => 'customers', 'cadence' => 'weekly', 'class' => CityPotentialDetector::class, 'phase' => 4],
        ['number' => 14, 'key' => 'discount_dependency', 'category' => 'customers', 'cadence' => 'weekly', 'class' => DiscountDependencyDetector::class, 'phase' => 4],
        ['number' => 15, 'key' => 'essential_flow_inactive', 'category' => 'messaging', 'cadence' => 'nightly', 'class' => EssentialFlowInactiveDetector::class, 'phase' => 4],
        ['number' => 16, 'key' => 'whatsapp_quality', 'category' => 'messaging', 'cadence' => 'nightly', 'class' => WhatsappQualityDetector::class, 'phase' => 4],
        ['number' => 17, 'key' => 'email_reputation', 'category' => 'messaging', 'cadence' => 'nightly', 'class' => EmailReputationDetector::class, 'phase' => 4],
        ['number' => 18, 'key' => 'fatigue', 'category' => 'messaging', 'cadence' => 'weekly', 'class' => FatigueDetector::class, 'phase' => 4],
        ['number' => 19, 'key' => 'conversions_failing', 'category' => 'measurement', 'cadence' => 'nightly', 'class' => ConversionsFailingDetector::class, 'phase' => 4],
        ['number' => 20, 'key' => 'data_drift', 'category' => 'measurement', 'cadence' => 'nightly', 'class' => DataDriftDetector::class, 'phase' => 1],
        ['number' => 21, 'key' => 'leads_unanswered', 'category' => 'services', 'cadence' => 'nightly', 'class' => LeadsUnansweredDetector::class, 'phase' => 5],
        ['number' => 22, 'key' => 'form_low_conversion', 'category' => 'services', 'cadence' => 'weekly', 'class' => FormLowConversionDetector::class, 'phase' => 5],
        ['number' => 23, 'key' => 'lead_source_close', 'category' => 'services', 'cadence' => 'weekly', 'class' => LeadSourceCloseDetector::class, 'phase' => 5],
    ];

    /** @return list<array{number: int, key: string, category: string, cadence: 'nightly'|'weekly', class: class-string<Detector>|null, phase: int}> */
    public static function all(): array
    {
        return self::ENTRIES;
    }

    /** @return array{number: int, key: string, category: string, cadence: 'nightly'|'weekly', class: class-string<Detector>|null, phase: int}|null */
    public static function find(string $key): ?array
    {
        foreach (self::ENTRIES as $entry) {
            if ($entry['key'] === $key) {
                return $entry;
            }
        }

        return null;
    }

    public static function category(string $key): string
    {
        return self::find($key)['category'] ?? 'sales';
    }
}
