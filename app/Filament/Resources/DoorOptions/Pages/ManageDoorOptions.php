<?php

declare(strict_types=1);

namespace App\Filament\Resources\DoorOptions\Pages;

use App\Enums\DoorOptionCategory;
use App\Filament\Resources\DoorOptions\DoorOptionResource;
use App\Models\DoorOption;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

class ManageDoorOptions extends ManageRecords
{
    protected static string $resource = DoorOptionResource::class;

    public function getTitle(): string
    {
        return 'Прайс конфигуратора';
    }

    public function getSubheading(): ?string
    {
        return 'Из этих позиций калькулятор собирает цену двери. Порядок меняется перетаскиванием.';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Новая позиция')];
    }

    /** Сводка идёт над таблицей: по ней сразу видно, где прайс не заполнен. */
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.resources.door-options.summary'),
            EmbeddedTable::make(),
        ]);
    }

    /**
     * Сводка по прайсу над таблицей.
     *
     * @return list<array{label: string, value: string, hint: string}>
     */
    public function summary(): array
    {
        $options = DoorOption::query()->get();
        $active = $options->where('is_active', true);

        $byCategory = $active->groupBy(fn (DoorOption $option): string => $option->category->value);

        return [
            [
                'label' => 'Позиций в прайсе',
                'value' => (string) $active->count(),
                'hint' => $options->count() - $active->count() > 0
                    ? 'выключено: '.($options->count() - $active->count())
                    : 'все активны',
            ],
            [
                'label' => 'Групп заполнено',
                'value' => $byCategory->count().' из '.count(DoorOptionCategory::cases()),
                'hint' => self::emptyGroups($byCategory->keys()->all()),
            ],
            [
                'label' => 'Списываются со склада',
                'value' => (string) $active->whereNotNull('material_stock_id')->count(),
                'hint' => 'остальные — только цена',
            ],
        ];
    }

    /** @param list<string> $filled */
    private static function emptyGroups(array $filled): string
    {
        $empty = collect(DoorOptionCategory::cases())
            ->reject(fn (DoorOptionCategory $category): bool => in_array($category->value, $filled, true))
            ->map(fn (DoorOptionCategory $category): string => $category->getLabel());

        return $empty->isEmpty() ? 'пустых нет' : 'пусто: '.$empty->implode(', ');
    }
}
