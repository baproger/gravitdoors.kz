<?php

declare(strict_types=1);

namespace App\Support;

use Closure;

/**
 * Правила, которые нужны в нескольких формах.
 *
 * Телефон проверяем по цифрам, а не по маске: в базе уже лежат номера в разных
 * форматах, и жёсткий шаблон ломал бы редактирование старых сделок.
 */
final class Validation
{
    public const PHONE_MASK = '+7 (999) 999-99-99';

    /**
     * Разрешённые символы номера. Встроенный regex Filament::tel() не пропускает
     * скобки после кода страны, из-за чего «+7 (707) …» считался невалидным.
     */
    public const PHONE_REGEX = '/^[\d\s\-\+\(\)]{7,25}$/';

    /** Казахстанский номер: 11 цифр, начинается с 7. */
    public static function phone(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (blank($value)) {
                return;
            }

            $digits = preg_replace('/\D+/', '', (string) $value) ?? '';

            if (mb_strlen($digits) !== 11 || ! str_starts_with($digits, '7')) {
                $fail('Введите номер в формате +7 (7XX) XXX-XX-XX.');
            }
        };
    }

    /** БИН или ИИН — ровно 12 цифр. */
    public static function bin(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (blank($value)) {
                return;
            }

            if (! preg_match('/^\d{12}$/', (string) $value)) {
                $fail('БИН или ИИН состоит ровно из 12 цифр.');
            }
        };
    }
}
