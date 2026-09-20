<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\DealSource;
use App\Models\Deal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Набор условий, по которым отбираются карточки на воронке.
 *
 * Отдельный объект, а не восемь параметров у `board()`: условия нужны в двух
 * местах — при подсчёте карточек в шапке колонки и при выборке самих карточек, —
 * и разъехаться они не должны, иначе колонка покажет «12», а карточек будет семь.
 *
 * Наряд завода копирует у сделки не всё: города, источника и оплаты у него нет.
 * Поэтому такие условия проверяются и у самой записи, и у её сделки — иначе на
 * воронке цеха фильтр по городу всегда давал бы пустой экран.
 */
final class BoardFilter
{
    public function __construct(
        public readonly ?string $search = null,
        public readonly ?int $managerId = null,
        /** «Только свои»: не выбор пользователя, а его уровень доступа. */
        public readonly ?int $ownerId = null,
        public readonly ?string $city = null,
        public readonly ?string $source = null,
        public readonly ?string $dueFrom = null,
        public readonly ?string $dueUntil = null,
        public readonly bool $overdueOnly = false,
        /** 'paid' — рассчитались полностью, 'due' — есть остаток. */
        public readonly ?string $payment = null,
    ) {}

    /**
     * Собрать из свойств страницы. Пустая строка и «0» из формы — это «не выбрано».
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input, ?int $ownerId = null): self
    {
        $text = static function (string $key) use ($input): ?string {
            $value = trim((string) ($input[$key] ?? ''));

            return $value === '' ? null : $value;
        };

        $number = static function (string $key) use ($input): ?int {
            $value = $input[$key] ?? null;

            return blank($value) ? null : (int) $value;
        };

        return new self(
            search: $text('search'),
            managerId: $number('managerId'),
            ownerId: $ownerId,
            city: $text('city'),
            source: $text('source'),
            dueFrom: $text('dueFrom'),
            dueUntil: $text('dueUntil'),
            overdueOnly: (bool) ($input['overdueOnly'] ?? false),
            payment: $text('payment'),
        );
    }

    /** @param  Builder<Deal>  $query */
    public function apply(Builder $query): void
    {
        $query
            // «Только свои»: сделка без ответственного остаётся видимой, иначе потеряется.
            ->when($this->ownerId, fn (Builder $q, int $owner) => $q->where(
                fn (Builder $inner) => $inner->where('manager_id', $owner)->orWhereNull('manager_id')
            ))
            ->when($this->managerId, fn (Builder $q, int $id) => $q->where('manager_id', $id))
            ->when($this->search, fn (Builder $q, string $text) => $q->where(
                fn (Builder $inner) => $inner
                    ->where('title', 'like', "%{$text}%")
                    ->orWhere('number', 'like', "%{$text}%")
                    ->orWhere('client_name', 'like', "%{$text}%")
                    ->orWhere('client_company', 'like', "%{$text}%")
                    ->orWhere('client_phone', 'like', "%{$text}%")
            ))
            ->when($this->dueFrom, fn (Builder $q, string $date) => $q->whereDate('due_date', '>=', $date))
            ->when($this->dueUntil, fn (Builder $q, string $date) => $q->whereDate('due_date', '<=', $date))
            ->when($this->overdueOnly, fn (Builder $q) => $q->whereNotNull('due_date')->whereDate('due_date', '<', today()))
            ->when($this->city, fn (Builder $q, string $city) => $this->matchOnDeal($q, 'city', $city))
            ->when($this->source, fn (Builder $q, string $source) => $this->matchOnDeal($q, 'source', $source))
            ->when($this->payment, fn (Builder $q, string $state) => $this->matchPayment($q, $state));
    }

    /**
     * Условие про саму запись или про её сделку продаж.
     *
     * Город и источник живут в сделке; наряд их не копирует. Спрашиваем у
     * обоих, чтобы на воронке цеха фильтр работал так же, как в продажах.
     *
     * @param  Builder<Deal>  $query
     */
    private function matchOnDeal(Builder $query, string $column, string $value): void
    {
        $query->where(fn (Builder $outer) => $outer
            ->where($column, $value)
            ->orWhereHas('parentDeal', fn (Builder $parent) => $parent->where($column, $value)));
    }

    /**
     * Оплата — тоже про сделку: у наряда нет ни предоплаты, ни платежей.
     *
     * @param  Builder<Deal>  $query
     */
    private function matchPayment(Builder $query, string $state): void
    {
        $query->where(function (Builder $outer) use ($state): void {
            $outer
                ->where(fn (Builder $self) => $state === 'paid'
                    ? $self->whereColumn('prepayment', '>=', 'total_price')
                    : $self->whereColumn('prepayment', '<', 'total_price'))
                ->orWhereHas('parentDeal', fn (Builder $parent) => $state === 'paid'
                    ? $parent->whereColumn('prepayment', '>=', 'total_price')
                    : $parent->whereColumn('prepayment', '<', 'total_price'));
        });
    }

    /** Сколько условий выбрал пользователь. «Только свои» не в счёт — это не его выбор. */
    public function activeCount(): int
    {
        return count(array_filter([
            $this->search, $this->managerId, $this->city, $this->source,
            $this->dueFrom, $this->dueUntil, $this->payment, $this->overdueOnly,
        ]));
    }

    /** Подписи выбранного — для строки «показаны: …» над колонками. */
    public function labels(): array
    {
        $dates = match (true) {
            $this->dueFrom && $this->dueUntil => 'срок '.self::date($this->dueFrom).' — '.self::date($this->dueUntil),
            (bool) $this->dueFrom => 'срок с '.self::date($this->dueFrom),
            (bool) $this->dueUntil => 'срок по '.self::date($this->dueUntil),
            default => null,
        };

        return array_values(array_filter([
            $this->search === null ? null : '«'.$this->search.'»',
            $this->city,
            $this->source === null ? null : (DealSource::tryFrom($this->source)?->getLabel() ?? $this->source),
            $dates,
            $this->overdueOnly ? 'только просроченные' : null,
            match ($this->payment) {
                'paid' => 'оплачено полностью',
                'due' => 'есть остаток',
                default => null,
            },
        ]));
    }

    private static function date(string $value): string
    {
        return Carbon::parse($value)->format('d.m.Y');
    }
}
