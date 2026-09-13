<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DoorOptionCategory;
use App\Enums\PriceType;
use App\Exceptions\PriceListException;
use App\Models\DoorConfiguration;
use App\Models\DoorOption;
use App\Models\MaterialStock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Правка прайса конфигуратора.
 *
 * Позиция прайса записана в дверях заказов по паре «группа + код», поэтому
 * три правила здесь важнее удобства:
 *  - код и группа после создания не меняются — иначе позиция молча выпала
 *    бы из расчёта старых заказов;
 *  - позицию, выбранную хоть в одной двери, нельзя удалить — только снять с
 *    продажи (is_active = false): новым дверям её не предложат, а в старых
 *    она продолжает считаться;
 *  - вариант по умолчанию в группе один.
 */
class PriceListService
{
    /** @param array<string, mixed> $data */
    public function create(array $data): DoorOption
    {
        $category = $this->category($data['category'] ?? null);
        $clean = $this->clean($data, $category);

        return DB::transaction(function () use ($category, $clean, $data): DoorOption {
            $option = DoorOption::create([
                ...$clean,
                'category' => $category->value,
                'code' => $this->uniqueCode($category, filled($data['code'] ?? null) ? (string) $data['code'] : $clean['label']),
                'sort' => (int) DoorOption::query()->ofCategory($category)->max('sort') + 10,
                'is_default' => false,
            ]);

            if (! empty($data['is_default']) && $option->is_active) {
                $this->setDefault($option, true);
            }

            return $option->refresh();
        });
    }

    /**
     * Код и группа из $data игнорируются: по ним позиция уже записана в заказах.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(DoorOption $option, array $data): void
    {
        $clean = $this->clean($data, $option->category, $option);

        DB::transaction(function () use ($option, $clean, $data): void {
            $option->update($clean);

            if (! $option->is_active) {
                $option->update(['is_default' => false]);

                return;
            }

            if (array_key_exists('is_default', $data)) {
                $this->setDefault($option->refresh(), (bool) $data['is_default']);
            }
        });
    }

    public function setDefault(DoorOption $option, bool $on): void
    {
        if (! $on) {
            $option->update(['is_default' => false]);

            return;
        }

        if ($option->category === DoorOptionCategory::Additional) {
            throw PriceListException::additionalHasNoDefault();
        }

        if (! $option->is_active) {
            throw PriceListException::inactiveCannotBeDefault($option);
        }

        DB::transaction(function () use ($option): void {
            DoorOption::query()
                ->ofCategory($option->category)
                ->whereKeyNot($option->id)
                ->update(['is_default' => false]);

            $option->update(['is_default' => true]);
        });
    }

    /** Снятая с продажи позиция перестаёт быть вариантом по умолчанию. */
    public function setActive(DoorOption $option, bool $active): void
    {
        $option->update(['is_active' => $active] + ($active ? [] : ['is_default' => false]));
    }

    /** Сдвиг внутри группы на одну позицию; у края ничего не происходит. */
    public function move(DoorOption $option, int $direction): void
    {
        DB::transaction(function () use ($option, $direction): void {
            $ids = $this->orderedIds($option->category);
            $index = array_search($option->id, $ids, true);
            $target = $index === false ? -1 : $index + ($direction < 0 ? -1 : 1);

            if ($index === false || $target < 0 || $target >= count($ids)) {
                return;
            }

            [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];

            $this->renumber($ids);
        });
    }

    public function delete(DoorOption $option): void
    {
        $doors = $this->usageOf($option);

        if ($doors > 0) {
            throw PriceListException::inUse($option, $doors);
        }

        DB::transaction(function () use ($option): void {
            $category = $option->category;
            $option->delete();

            $this->renumber($this->orderedIds($category));
        });
    }

    /** В скольких дверях заказов выбрана позиция — с учётом заказов в корзине. */
    public function usageOf(DoorOption $option): int
    {
        $column = $option->category->configurationColumn();

        return $column === null
            ? DoorConfiguration::query()->whereJsonContains('additional_options', $option->code)->count()
            : DoorConfiguration::query()->where($column, $option->code)->count();
    }

    /**
     * Использование всех позиций разом, одним запросом — для страницы прайса.
     *
     * @return array<string, int> «группа|код» => число дверей
     */
    public function usageMap(): array
    {
        $columns = collect(DoorOptionCategory::cases())
            ->map(fn (DoorOptionCategory $category): ?string => $category->configurationColumn())
            ->filter()
            ->values()
            ->all();

        $map = [];

        foreach (DoorConfiguration::query()->get([...$columns, 'additional_options']) as $configuration) {
            foreach (DoorOptionCategory::cases() as $category) {
                $column = $category->configurationColumn();

                $codes = $column === null
                    ? array_filter((array) $configuration->additional_options)
                    : array_filter([$configuration->{$column}]);

                foreach (array_unique($codes) as $code) {
                    $key = $category->value.'|'.$code;
                    $map[$key] = ($map[$key] ?? 0) + 1;
                }
            }
        }

        return $map;
    }

    private function category(mixed $value): DoorOptionCategory
    {
        if ($value instanceof DoorOptionCategory) {
            return $value;
        }

        return DoorOptionCategory::tryFrom((string) $value) ?? throw PriceListException::unknownCategory();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{label: string, price: float, price_type: string, consumption: float, material_stock_id: ?int, is_active: bool}
     */
    private function clean(array $data, DoorOptionCategory $category, ?DoorOption $ignore = null): array
    {
        $label = trim((string) preg_replace('/\s+/u', ' ', (string) ($data['label'] ?? '')));

        if (mb_strlen($label) < 2) {
            throw PriceListException::labelTooShort();
        }

        if (mb_strlen($label) > 255) {
            throw PriceListException::labelTooLong();
        }

        $taken = DoorOption::query()
            ->ofCategory($category)
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->id))
            ->pluck('label')
            ->contains(fn (string $existing): bool => mb_strtolower($existing) === mb_strtolower($label));

        if ($taken) {
            throw PriceListException::duplicateLabel($label, $category->getLabel());
        }

        $price = round((float) ($data['price'] ?? 0), 2);

        if ($price < 0) {
            throw PriceListException::negativePrice();
        }

        $type = ($data['price_type'] ?? null) instanceof PriceType
            ? $data['price_type']
            : PriceType::tryFrom((string) ($data['price_type'] ?? ''));

        if ($type === null) {
            throw PriceListException::unknownPriceType();
        }

        $consumption = round((float) ($data['consumption'] ?? 0), 3);

        if ($consumption < 0) {
            throw PriceListException::negativeConsumption();
        }

        $materialId = filled($data['material_stock_id'] ?? null) ? (int) $data['material_stock_id'] : null;

        if ($materialId !== null && ! MaterialStock::query()->whereKey($materialId)->exists()) {
            throw PriceListException::unknownMaterial();
        }

        return [
            'label' => $label,
            'price' => $price,
            'price_type' => $type->value,
            // Расход без материала ничего не значит — не храним мусор.
            'consumption' => $materialId === null ? 0 : $consumption,
            'material_stock_id' => $materialId,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    /** @return list<int> */
    private function orderedIds(DoorOptionCategory $category): array
    {
        return DoorOption::query()
            ->ofCategory($category)
            ->orderBy('sort')
            ->orderBy('label')
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }

    /** @param list<int> $ids */
    private function renumber(array $ids): void
    {
        foreach ($ids as $index => $id) {
            DoorOption::query()->whereKey($id)->update(['sort' => ($index + 1) * 10]);
        }
    }

    private function uniqueCode(DoorOptionCategory $category, string $source): string
    {
        $base = Str::limit(Str::slug($source, '_'), 50, '') ?: 'option';
        $code = $base;

        for ($suffix = 2; DoorOption::query()->ofCategory($category)->where('code', $code)->exists(); $suffix++) {
            $code = "{$base}_{$suffix}";
        }

        return $code;
    }
}
