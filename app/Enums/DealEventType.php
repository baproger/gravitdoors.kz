<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum DealEventType: string implements HasColor, HasIcon, HasLabel
{
    case Created = 'created';
    case Updated = 'updated';
    case StageChanged = 'stage_changed';
    case HandedToProduction = 'handed_to_production';
    case ProductionStage = 'production_stage';
    case ProductionCompleted = 'production_completed';
    case ProductionCancelled = 'production_cancelled';
    case Materials = 'materials';
    case Payment = 'payment';
    case Document = 'document';
    case Survey = 'survey';
    case Cancelled = 'cancelled';
    case PaymentReminder = 'payment_reminder';

    public function getLabel(): string
    {
        return match ($this) {
            self::Created => 'Сделка создана',
            self::Updated => 'Правка карточки',
            self::StageChanged => 'Смена этапа',
            self::HandedToProduction => 'Передано в производство',
            self::ProductionStage => 'Этап цеха',
            self::ProductionCompleted => 'Производство завершено',
            self::ProductionCancelled => 'Наряд отменён',
            self::Materials => 'Движение материалов',
            self::Payment => 'Оплата',
            self::Document => 'Документы',
            self::Survey => 'Замер',
            self::Cancelled => 'Сделка отменена',
            self::PaymentReminder => 'Напоминание об оплате',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Created => 'gray',
            self::Updated => 'gray',
            self::StageChanged => 'info',
            self::HandedToProduction, self::ProductionStage => 'warning',
            self::ProductionCompleted => 'success',
            self::ProductionCancelled => 'danger',
            self::Materials => 'gray',
            self::Payment => 'success',
            self::Document => 'info',
            self::Survey => 'success',
            self::Cancelled => 'danger',
            self::PaymentReminder => 'warning',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Created => 'heroicon-o-plus-circle',
            self::Updated => 'heroicon-o-pencil-square',
            self::StageChanged => 'heroicon-o-arrow-right-circle',
            self::HandedToProduction => 'heroicon-o-truck',
            self::ProductionStage => 'heroicon-o-wrench-screwdriver',
            self::ProductionCompleted => 'heroicon-o-check-badge',
            self::ProductionCancelled => 'heroicon-o-x-circle',
            self::Materials => 'heroicon-o-archive-box',
            self::Payment => 'heroicon-o-banknotes',
            self::Document => 'heroicon-o-paper-clip',
            self::Survey => 'heroicon-o-map-pin',
            self::Cancelled => 'heroicon-o-no-symbol',
            self::PaymentReminder => 'heroicon-o-bell-alert',
        };
    }
}
