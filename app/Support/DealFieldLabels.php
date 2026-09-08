<?php

declare(strict_types=1);

namespace App\Support;

use Filament\Support\Contracts\HasLabel;

/**
 * Человеческие названия полей сделки для истории правок.
 *
 * Без словаря лента показывала бы «client_bin: 1503…», и понять, кто что менял,
 * было бы можно только зная схему базы.
 */
final class DealFieldLabels
{
    /** @var array<string, string> */
    private const LABELS = [
        'title' => 'Название сделки',
        'client_name' => 'Контактное лицо',
        'client_type' => 'Тип клиента',
        'client_company' => 'Название компании',
        'client_bin' => 'БИН / ИИН',
        'client_phone' => 'Телефон',
        'client_phone_extra' => 'Доп. телефон',
        'client_email' => 'E-mail',
        'client_address' => 'Адрес объекта',
        'city' => 'Город',
        'source' => 'Источник',
        'contract_number' => '№ договора',
        'contract_date' => 'Дата договора',
        'measured_at' => 'Дата замера',
        'documents' => 'Документы',
        'total_price' => 'Сумма сделки',
        'cost_price' => 'Себестоимость',
        'prepayment' => 'Предоплата',
        'payment_method' => 'Способ оплаты',
        'delivery_cost' => 'Доставка',
        'installation_cost' => 'Монтаж',
        'status_id' => 'Статус',
        'current_stage_id' => 'Этап',
        'manager_id' => 'Ответственный',
        'due_date' => 'Срок сдачи',
        'notes' => 'Заметка',
    ];

    /**
     * Поля, правка которых не идёт в ленту.
     *
     * Кроме служебных сюда попали: этап — у него своё событие «Смена этапа»,
     * и суммы — они пересчитываются из спецификации автоматически. Иначе один
     * перевод сделки давал три записи подряд об одном и том же.
     */
    private const HIDDEN = [
        'updated_at', 'created_at', 'stage_entered_at',
        'production_started_at', 'production_finished_at',
        'qr_code_hash', 'number', 'pipeline_type', 'parent_deal_id',
        'current_stage_id', 'total_price', 'cost_price',
    ];

    public static function label(string $field): string
    {
        return self::LABELS[$field] ?? $field;
    }

    public static function isTracked(string $field): bool
    {
        return ! in_array($field, self::HIDDEN, true);
    }

    /** Значение в читаемом виде: enum — подписью, деньги — с символом, пусто — прочерком. */
    public static function value(string $field, mixed $value): string
    {
        if (blank($value)) {
            return '—';
        }

        if ($value instanceof \BackedEnum) {
            return $value instanceof HasLabel
                ? (string) $value->getLabel()
                : (string) $value->value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('d.m.Y');
        }

        if (is_array($value)) {
            return count($value).' шт';
        }

        if (in_array($field, ['total_price', 'cost_price', 'prepayment', 'delivery_cost', 'installation_cost'], true)) {
            return Money::format((float) $value);
        }

        return (string) $value;
    }
}
