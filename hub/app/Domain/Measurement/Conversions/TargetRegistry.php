<?php

declare(strict_types=1);

namespace App\Domain\Measurement\Conversions;

use App\Domain\Measurement\Conversions\Platforms\DataManagerTarget;
use App\Domain\Measurement\Conversions\Platforms\Ga4Target;
use App\Domain\Measurement\Conversions\Platforms\MetaCapiTarget;
use App\Domain\Measurement\Conversions\Platforms\TikTokTarget;
use InvalidArgumentException;

final class TargetRegistry
{
    /** @var array<string, ConversionTarget> */
    private array $targets;

    public function __construct(MetaCapiTarget $meta, Ga4Target $ga4, DataManagerTarget $dataManager, TikTokTarget $tiktok)
    {
        foreach ([$meta, $ga4, $dataManager, $tiktok] as $target) {
            $this->targets[$target->key()] = $target;
        }
    }

    /** @return array<string, ConversionTarget> */
    public function all(): array
    {
        return $this->targets;
    }

    public function get(string $key): ConversionTarget
    {
        return $this->targets[$key] ?? throw new InvalidArgumentException("Destino de conversión desconocido: {$key}");
    }
}
