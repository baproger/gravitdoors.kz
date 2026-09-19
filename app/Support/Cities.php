<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Deal;

/**
 * Города для выпадающего списка в карточке сделки.
 *
 * Отдельной таблицы городов нет намеренно: город — такое же поле сделки, как
 * адрес, и заводить ради него справочник с экраном управления значит платить
 * больше, чем задача стоит (справочники вместо перечислений — отдельный шаг
 * плана настроек). Поэтому список складывается из двух источников: областные
 * центры Казахстана, чтобы он не был пустым с первого дня, и все города,
 * которые уже вводили в сделках, — так однажды добавленный город появляется
 * у всех менеджеров сам.
 */
final class Cities
{
    /**
     * Областные центры и города республиканского значения.
     *
     * @var list<string>
     */
    private const SEED = [
        'Алматы', 'Астана', 'Шымкент',
        'Актау', 'Актобе', 'Атырау', 'Жезказган', 'Караганда', 'Кокшетау',
        'Костанай', 'Кызылорда', 'Павлодар', 'Петропавловск', 'Семей',
        'Талдыкорган', 'Тараз', 'Туркестан', 'Уральск', 'Усть-Каменогорск',
        'Экибастуз',
    ];

    /** Копия на запрос: список спрашивает каждая позиция формы. */
    private static ?array $memo = null;

    /**
     * Варианты для Select: значение совпадает с подписью — в базе лежит
     * название города, а не код.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return self::$memo ??= collect(self::SEED)
            ->merge(self::used())
            ->map(fn (string $city): string => trim($city))
            ->filter()
            ->unique(fn (string $city): string => mb_strtolower($city))
            ->sort(fn (string $a, string $b): int => strcoll($a, $b))
            ->mapWithKeys(fn (string $city): array => [$city => $city])
            ->all();
    }

    /**
     * Города, которые уже встречаются в сделках.
     *
     * @return list<string>
     */
    private static function used(): array
    {
        return Deal::query()
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->distinct()
            ->pluck('city')
            ->all();
    }

    /**
     * Привести введённый вручную город к виду списка.
     *
     * «алматы» и «АЛМАТЫ» не должны стать третьим и четвёртым Алматы в
     * выпадающем списке, поэтому уже известный город возвращается в его
     * исходном написании, а новый — с заглавной буквы.
     */
    public static function normalize(?string $city): ?string
    {
        $city = trim((string) $city);

        if ($city === '') {
            return null;
        }

        foreach (self::options() as $known) {
            if (mb_strtolower($known) === mb_strtolower($city)) {
                return $known;
            }
        }

        self::$memo = null;

        return mb_convert_case(mb_strtolower($city), MB_CASE_TITLE, 'UTF-8');
    }

    public static function forget(): void
    {
        self::$memo = null;
    }
}
