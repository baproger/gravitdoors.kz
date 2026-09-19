<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Период отчёта для инфопанели.
 *
 * Не только месяц: смена смотрит «сегодня», руководитель — квартал и год,
 * а при сверке нужен произвольный отрезок. Значение живёт в адресной строке,
 * поэтому выбранный период переживает обновление страницы и делится ссылкой.
 */
final class Period
{
    public const TODAY = 'today';

    public const WEEK = 'week';

    public const MONTH = 'month';

    public const PREV_MONTH = 'prev_month';

    public const QUARTER = 'quarter';

    public const YEAR = 'year';

    public const ALL = 'all';

    public const CUSTOM = 'custom';

    private function __construct(
        public readonly string $key,
        public readonly ?CarbonImmutable $from,
        public readonly ?CarbonImmutable $to,
    ) {}

    /** @return array<string, string> ключ → подпись для переключателя */
    public static function options(): array
    {
        return [
            self::TODAY => 'Сегодня',
            self::WEEK => 'Неделя',
            self::MONTH => 'Этот месяц',
            self::PREV_MONTH => 'Прошлый месяц',
            self::QUARTER => 'Квартал',
            self::YEAR => 'Год',
            self::ALL => 'За всё время',
            self::CUSTOM => 'Свой период',
        ];
    }

    public static function make(string $key, ?string $from = null, ?string $to = null): self
    {
        $now = CarbonImmutable::now();

        return match ($key) {
            self::TODAY => new self($key, $now->startOfDay(), $now->endOfDay()),
            self::WEEK => new self($key, $now->startOfWeek(), $now->endOfWeek()),
            self::PREV_MONTH => new self($key, $now->subMonth()->startOfMonth(), $now->subMonth()->endOfMonth()),
            self::QUARTER => new self($key, $now->startOfQuarter(), $now->endOfQuarter()),
            self::YEAR => new self($key, $now->startOfYear(), $now->endOfYear()),
            self::ALL => new self($key, null, null),
            self::CUSTOM => self::custom($from, $to),
            default => new self(self::MONTH, $now->startOfMonth(), $now->endOfMonth()),
        };
    }

    private static function custom(?string $from, ?string $to): self
    {
        $start = $from ? CarbonImmutable::parse($from)->startOfDay() : null;
        $end = $to ? CarbonImmutable::parse($to)->endOfDay() : null;

        // Границы перепутали местами — меняем, а не показываем пустой отчёт.
        if ($start && $end && $start->gt($end)) {
            [$start, $end] = [$end->startOfDay(), $start->endOfDay()];
        }

        return new self(self::CUSTOM, $start, $end);
    }

    public function isAllTime(): bool
    {
        return $this->from === null && $this->to === null;
    }

    public function label(): string
    {
        if ($this->key !== self::CUSTOM) {
            return self::options()[$this->key] ?? 'Период';
        }

        return match (true) {
            $this->from && $this->to => $this->from->format('d.m.Y').' — '.$this->to->format('d.m.Y'),
            (bool) $this->from => 'с '.$this->from->format('d.m.Y'),
            (bool) $this->to => 'по '.$this->to->format('d.m.Y'),
            default => 'Свой период',
        };
    }

    /** Подпись в родительном падеже — для подзаголовков плиток. */
    public function hint(): string
    {
        return match ($this->key) {
            self::TODAY => 'за сегодня',
            self::WEEK => 'за неделю',
            self::MONTH => 'за этот месяц',
            self::PREV_MONTH => 'за прошлый месяц',
            self::QUARTER => 'за квартал',
            self::YEAR => 'за год',
            self::ALL => 'за всё время',
            default => $this->label(),
        };
    }

    /**
     * Ограничить запрос периодом по колонке даты.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function apply(Builder $query, string $column): Builder
    {
        return $query
            ->when($this->from, fn ($q) => $q->where($column, '>=', $this->from))
            ->when($this->to, fn ($q) => $q->where($column, '<=', $this->to));
    }

    /** Предыдущий отрезок такой же длины — для сравнения «было / стало». */
    public function previous(): ?self
    {
        if ($this->from === null || $this->to === null) {
            return null;
        }

        $length = $this->from->diffInSeconds($this->to);

        return new self(
            $this->key.'_prev',
            $this->from->subSeconds($length + 1),
            $this->from->subSecond(),
        );
    }

    /** Ключ месяца для ведомостей и бонусов, которые хранятся как «ГГГГ-ММ». */
    public function monthKey(): ?string
    {
        return $this->from?->format('Y-m');
    }
}
