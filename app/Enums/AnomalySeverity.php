<?php

namespace App\Enums;

enum AnomalySeverity: string
{
    case INFO = 'info';
    case WARN = 'warn';
    case HIGH = 'high';

    public function label(): string
    {
        return match ($this) {
            self::INFO => 'For information',
            self::WARN => 'Worth checking',
            self::HIGH => 'Needs attention',
        };
    }
}
