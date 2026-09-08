<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Две связанные воронки системы. Сделка живёт ровно в одной из них,
 * производственный наряд (pipeline_type = factory) ссылается на родительскую
 * сделку отдела продаж через deals.parent_deal_id.
 */
enum PipelineType: string implements HasColor, HasIcon, HasLabel
{
    case Sales = 'sales';
    case Factory = 'factory';

    public function getLabel(): string
    {
        return match ($this) {
            self::Sales => 'Отдел продаж',
            self::Factory => 'Завод / Производство',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Sales => 'info',
            self::Factory => 'warning',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Sales => 'heroicon-o-briefcase',
            self::Factory => 'heroicon-o-cog-6-tooth',
        };
    }
}
