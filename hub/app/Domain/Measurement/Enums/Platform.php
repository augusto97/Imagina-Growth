<?php

declare(strict_types=1);

namespace App\Domain\Measurement\Enums;

enum Platform: string
{
    case Meta = 'meta';
    case Google = 'google';
    case TikTok = 'tiktok';

    public function queue(): string
    {
        return 'conversions:'.$this->value;
    }

    public function label(): string
    {
        return match ($this) {
            self::Meta => 'Meta',
            self::Google => 'Google',
            self::TikTok => 'TikTok',
        };
    }
}
