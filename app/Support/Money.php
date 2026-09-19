<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Единый формат денег во всей системе.
 *
 * Filament money() с русской локалью печатает «987 654,00 KZT», а канбан и
 * страница клиента — «987 654 ₸». Разный вид одной и той же суммы на соседних
 * экранах читается как разные суммы, поэтому формат один и живёт здесь.
 */
final class Money
{
    public static function format(float|int|string|null $value, bool $withFraction = false): string
    {
        $value = (float) ($value ?? 0);

        // Перед знаком валюты — неразрывный пробел: в узкой плитке «231 340 ₸»
        // переносился так, что ₸ уезжал на отдельную строку.
        return number_format($value, $withFraction ? 2 : 0, ',', ' ')
            ."\u{00A0}".config('gravit.currency.symbol');
    }
}
