<?php

declare(strict_types=1);

namespace App\Filament\Resources\DoorOptions\Pages;

use App\Enums\DoorOptionCategory;
use App\Enums\PriceType;
use App\Exceptions\PriceListException;
use App\Filament\Resources\DoorOptions\DoorOptionResource;
use App\Filament\Resources\DoorOptions\Schemas\DoorOptionForm;
use App\Models\DoorOption;
use App\Services\PriceListService;
use App\Support\Money;
use App\Support\Plural;
use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * Прайс конфигуратора bento-сеткой: карточка на каждую группу опций.
 *
 * Правки идут через PriceListService: он не даёт удалить позицию, выбранную в
 * заказах, и держит один вариант по умолчанию в группе. Менеджер прайс видит,
 * но не правит — кнопки правки у него не выводятся, методы закрыты политикой.
 */
class PriceList extends Page
{
    protected static string $resource = DoorOptionResource::class;

    protected string $view = 'filament.resources.door-options.price-list';

    /**
     * Раскладка bento: группа, ширина из 12 колонок, цвет акцента, иконка.
     * Короткие группы (по три позиции) — по три в ряд, длинные — по две.
     */
    private const LAYOUT = [
        [DoorOptionCategory::MetalThickness, 4, 'steel', 'heroicon-o-cube'],
        [DoorOptionCategory::InsulationType, 4, 'teal', 'heroicon-o-shield-check'],
        [DoorOptionCategory::ColorCoating, 4, 'rose', 'heroicon-o-swatch'],
        [DoorOptionCategory::OuterMdfPanel, 6, 'amber', 'heroicon-o-rectangle-stack'],
        [DoorOptionCategory::InnerMdfPanel, 6, 'orange', 'heroicon-o-rectangle-group'],
        [DoorOptionCategory::LockSystem, 6, 'violet', 'heroicon-o-lock-closed'],
        [DoorOptionCategory::Additional, 6, 'blue', 'heroicon-o-sparkles'],
    ];

    private const FILTERS = [
        'all' => 'Все',
        'stock' => 'Со склада',
        'used' => 'В заказах',
        'inactive' => 'Сняты с продажи',
    ];

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: 'all')]
    public string $filter = 'all';

    /** @var array<string, int>|null */
    private ?array $usage = null;

    public function getTitle(): string
    {
        return 'Прайс конфигуратора';
    }

    public function getSubheading(): ?string
    {
        return 'Цены, из которых собирается стоимость двери. ★ — вариант по умолчанию в новой двери.';
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }

    public function canEdit(): bool
    {
        return auth()->user()?->can('create', DoorOption::class) ?? false;
    }

    /** @return array<string, string> */
    public function filters(): array
    {
        return self::FILTERS;
    }

    /** @return array<string, int> */
    public function filterCounts(): array
    {
        $options = DoorOption::query()->get();
        $usage = $this->usage();

        return [
            'all' => $options->count(),
            'stock' => $options->whereNotNull('material_stock_id')->count(),
            'used' => $options->filter(fn (DoorOption $o): bool => ($usage[$this->key($o)] ?? 0) > 0)->count(),
            'inactive' => $options->where('is_active', false)->count(),
        ];
    }

    /** @return list<array{label: string, value: string, hint: string, span: int, hero: bool}> */
    public function summary(): array
    {
        $options = DoorOption::query()->get();
        $active = $options->where('is_active', true);
        $usage = $this->usage();
        $filled = $active->map(fn (DoorOption $o): string => $o->category->value)->unique();
        $empty = collect(DoorOptionCategory::cases())
            ->reject(fn (DoorOptionCategory $c): bool => $filled->contains($c->value))
            ->map(fn (DoorOptionCategory $c): string => $c->getLabel());
        $inactive = $options->count() - $active->count();
        $used = $options->filter(fn (DoorOption $o): bool => ($usage[$this->key($o)] ?? 0) > 0)->count();

        return [
            [
                'label' => 'Позиций в продаже',
                'value' => (string) $active->count(),
                'hint' => $inactive > 0 ? "ещё {$inactive} ".Plural::choose($inactive, 'снята', 'сняты', 'сняты').' с продажи' : 'всё в продаже',
                'span' => 4,
                'hero' => true,
            ],
            [
                'label' => 'Групп заполнено',
                'value' => $filled->count().' из '.count(DoorOptionCategory::cases()),
                'hint' => $empty->isEmpty() ? 'пустых нет' : 'пусто: '.$empty->implode(', '),
                'span' => 3,
                'hero' => false,
            ],
            [
                'label' => 'Со склада',
                'value' => (string) $active->whereNotNull('material_stock_id')->count(),
                'hint' => 'списываются в цех',
                'span' => 2,
                'hero' => false,
            ],
            [
                'label' => 'В заказах',
                'value' => (string) $used,
                'hint' => Plural::choose($used, 'позиция выбрана', 'позиции выбраны', 'позиций выбрано').' в дверях',
                'span' => 3,
                'hero' => false,
            ],
        ];
    }

    /**
     * Карточки групп. При поиске или фильтре пустые группы скрываются.
     *
     * @return list<array{category: DoorOptionCategory, span: int, accent: string, icon: string, options: Collection<int, DoorOption>, total: int, meta: string}>
     */
    public function groups(): array
    {
        $all = DoorOption::query()->with('materialStock')->orderBy('sort')->orderBy('label')->get()
            ->groupBy(fn (DoorOption $o): string => $o->category->value);
        $needle = mb_strtolower(trim($this->search));
        $filtering = $needle !== '' || $this->filter !== 'all';

        return collect(self::LAYOUT)
            ->map(function (array $cell) use ($all, $needle): array {
                [$category, $span, $accent, $icon] = $cell;
                $options = $all->get($category->value, collect());

                return [
                    'category' => $category,
                    'span' => $span,
                    'accent' => $accent,
                    'icon' => $icon,
                    'options' => $options->filter(fn (DoorOption $o): bool => $this->matches($o, $needle))->values(),
                    'total' => $options->count(),
                    'meta' => $this->groupMeta($options),
                ];
            })
            ->filter(fn (array $group): bool => ! $filtering || $group['options']->isNotEmpty())
            ->values()
            ->all();
    }

    public function usageOf(DoorOption $option): int
    {
        return $this->usage()[$this->key($option)] ?? 0;
    }

    public function unit(PriceType $type): string
    {
        return match ($type) {
            PriceType::Fixed => 'за шт',
            PriceType::PerSquareMeter => 'за м²',
            PriceType::PerMeter => 'за м.п.',
        };
    }

    public function stockNote(DoorOption $option): ?string
    {
        if (! $option->materialStock) {
            return null;
        }

        $amount = rtrim(rtrim(number_format((float) $option->consumption, 3, '.', ''), '0'), '.');

        return $option->materialStock->name.((float) $option->consumption > 0 ? " · {$amount} ".$option->materialStock->unit->getLabel() : '');
    }

    public function moveOption(int $id, int $direction): void
    {
        $this->attempt(fn () => $this->service()->move($this->option($id), $direction));
    }

    public function toggleActive(int $id): void
    {
        $option = $this->option($id);

        $this->attempt(function () use ($option): void {
            $this->service()->setActive($option, ! $option->is_active);
            $this->notify($option->is_active ? "«{$option->label}» снова в продаже" : "«{$option->label}» снята с продажи — в старых заказах продолжает считаться");
        });
    }

    public function toggleDefault(int $id): void
    {
        $option = $this->option($id);

        $this->attempt(function () use ($option): void {
            $this->service()->setDefault($option, ! $option->is_default);
            $this->notify($option->is_default ? "«{$option->label}» — вариант по умолчанию" : 'Вариант по умолчанию снят');
        });
    }

    public function createOptionAction(): Action
    {
        return Action::make('createOption')
            ->visible(fn (): bool => $this->canEdit())
            ->modalHeading(function (array $arguments): string {
                $category = DoorOptionCategory::tryFrom((string) ($arguments['category'] ?? ''));

                return $category ? 'Новая позиция · '.$category->getLabel() : 'Новая позиция прайса';
            })
            ->modalWidth(Width::FourExtraLarge)
            ->modalSubmitActionLabel('Добавить')
            ->fillForm(fn (array $arguments): array => [
                'category' => $arguments['category'] ?? null,
                'price' => 0,
                'price_type' => PriceType::Fixed->value,
                'consumption' => 0,
                'is_active' => true,
                'is_default' => false,
            ])
            ->schema(fn (): array => DoorOptionForm::components())
            ->action(function (array $data, Action $action): void {
                abort_unless($this->canEdit(), 403);

                try {
                    $option = $this->service()->create($data);
                    $this->notify("«{$option->label}» добавлена в «{$option->category->getLabel()}»");
                } catch (PriceListException $e) {
                    $this->fail($e);
                    $action->halt();
                }
            });
    }

    public function editOptionAction(): Action
    {
        return Action::make('editOption')
            ->visible(fn (): bool => $this->canEdit())
            ->modalHeading(fn (array $arguments): string => 'Изменить «'.$this->optionFrom($arguments)->label.'»')
            ->modalWidth(Width::FourExtraLarge)
            ->modalSubmitActionLabel('Сохранить')
            ->fillForm(function (array $arguments): array {
                $option = $this->optionFrom($arguments);

                return [
                    'category' => $option->category->value,
                    'label' => $option->label,
                    'code' => $option->code,
                    'price' => (float) $option->price,
                    'price_type' => $option->price_type->value,
                    'material_stock_id' => $option->material_stock_id,
                    'consumption' => (float) $option->consumption,
                    'is_default' => $option->is_default,
                    'is_active' => $option->is_active,
                ];
            })
            ->schema(fn (): array => DoorOptionForm::components(editing: true))
            ->action(function (array $data, array $arguments, Action $action): void {
                $option = $this->optionFrom($arguments);

                try {
                    $this->service()->update($option, $data);
                    $this->notify('Позиция сохранена');
                } catch (PriceListException $e) {
                    $this->fail($e);
                    $action->halt();
                }
            });
    }

    public function deleteOptionAction(): Action
    {
        return Action::make('deleteOption')
            ->visible(fn (): bool => $this->canEdit())
            ->requiresConfirmation()
            ->color('danger')
            ->icon('heroicon-o-trash')
            ->modalHeading(fn (array $arguments): string => 'Удалить «'.$this->optionFrom($arguments)->label.'»?')
            ->modalDescription(function (array $arguments): string {
                $doors = $this->service()->usageOf($this->optionFrom($arguments));

                return $doors > 0
                    ? "Позиция выбрана в {$doors} ".Plural::choose($doors, 'двери', 'дверях', 'дверях').' — удалить её не получится. Снимите с продажи переключателем.'
                    : 'Позиция не выбрана ни в одном заказе. Удаление нельзя отменить.';
            })
            ->modalSubmitActionLabel('Удалить')
            ->action(function (array $arguments, Action $action): void {
                $option = $this->optionFrom($arguments);
                $label = $option->label;

                try {
                    $this->service()->delete($option);
                    $this->notify("«{$label}» удалена");
                } catch (PriceListException $e) {
                    $this->fail($e);
                    $action->halt();
                }
            });
    }

    private function matches(DoorOption $option, string $needle): bool
    {
        if ($needle !== ''
            && ! str_contains(mb_strtolower($option->label), $needle)
            && ! str_contains(mb_strtolower($option->code), $needle)) {
            return false;
        }

        return match ($this->filter) {
            'stock' => $option->material_stock_id !== null,
            'used' => $this->usageOf($option) > 0,
            'inactive' => ! $option->is_active,
            default => true,
        };
    }

    /** @param Collection<int, DoorOption> $options */
    private function groupMeta(Collection $options): string
    {
        $active = $options->where('is_active', true);

        if ($active->isEmpty()) {
            return 'Нет позиций в продаже';
        }

        $prices = $active->map(fn (DoorOption $o): float => (float) $o->price);
        $types = $active->map(fn (DoorOption $o): string => $o->price_type->value)->unique();

        $range = $prices->min() === $prices->max()
            ? Money::format($prices->min())
            : Money::format($prices->min()).' – '.Money::format($prices->max());

        return $types->count() === 1
            ? $range.' '.$this->unit(PriceType::from($types->first()))
            : $range;
    }

    /** @return array<string, int> */
    private function usage(): array
    {
        return $this->usage ??= $this->service()->usageMap();
    }

    private function key(DoorOption $option): string
    {
        return $option->category->value.'|'.$option->code;
    }

    private function attempt(Closure $change): void
    {
        try {
            $change();
        } catch (PriceListException $e) {
            $this->fail($e);
        }
    }

    /** @param array<string, mixed> $arguments */
    private function optionFrom(array $arguments): DoorOption
    {
        return $this->option((int) ($arguments['option'] ?? 0));
    }

    private function option(int $id): DoorOption
    {
        $option = DoorOption::query()->with('materialStock')->findOrFail($id);

        abort_unless(auth()->user()?->can('update', $option), 403);

        return $option;
    }

    private function service(): PriceListService
    {
        return app(PriceListService::class);
    }

    private function notify(string $title): void
    {
        Notification::make()->success()->title($title)->send();
    }

    private function fail(PriceListException $e): void
    {
        Notification::make()->danger()->title('Не получилось')->body($e->getMessage())->send();
    }
}
