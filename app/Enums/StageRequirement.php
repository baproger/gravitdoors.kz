<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Deal;
use Filament\Support\Contracts\HasLabel;

/**
 * Что должно быть заполнено, чтобы сделку пустили на этап.
 *
 * Набор требований у каждого этапа настраивается в админке
 * (factory_stages.required_fields), поэтому регламент отдела продаж меняется
 * без правки кода. Здесь — только словарь возможных проверок.
 */
enum StageRequirement: string implements HasLabel
{
    case ClientPhone = 'client_phone';
    case ClientEmail = 'client_email';
    case ClientAddress = 'client_address';
    case City = 'city';
    case CompanyDetails = 'company_details';
    case Manager = 'manager';
    case MeasuredAt = 'measured_at';
    case Doors = 'doors';
    case DueDate = 'due_date';
    case ContractNumber = 'contract_number';
    case ContractDate = 'contract_date';
    case Documents = 'documents';
    case Prepayment = 'prepayment';
    case PaidInFull = 'paid_in_full';

    public function getLabel(): string
    {
        return match ($this) {
            self::ClientPhone => 'Телефон клиента',
            self::ClientEmail => 'E-mail клиента',
            self::ClientAddress => 'Адрес объекта',
            self::City => 'Город',
            self::CompanyDetails => 'Реквизиты компании (название и БИН)',
            self::Manager => 'Назначен ответственный',
            self::MeasuredAt => 'Дата замера',
            self::Doors => 'Добавлена хотя бы одна дверь',
            self::DueDate => 'Срок сдачи',
            self::ContractNumber => 'Номер договора',
            self::ContractDate => 'Дата договора',
            self::Documents => 'Загружен договор',
            self::Prepayment => 'Внесена предоплата',
            self::PaidInFull => 'Сделка оплачена полностью',
        };
    }

    public function isSatisfiedBy(Deal $deal): bool
    {
        $deal = $deal->salesDeal();

        return match ($this) {
            self::ClientPhone => filled($deal->client_phone),
            self::ClientEmail => filled($deal->client_email),
            self::ClientAddress => filled($deal->client_address),
            self::City => filled($deal->city),
            // Для физлица реквизитов не бывает — требование к нему неприменимо.
            self::CompanyDetails => $deal->client_type !== ClientType::Company
                || (filled($deal->client_company) && filled($deal->client_bin)),
            self::Manager => $deal->manager_id !== null,
            self::MeasuredAt => $deal->measured_at !== null,
            self::Doors => $deal->doorConfigurations()->exists(),
            self::DueDate => $deal->due_date !== null,
            self::ContractNumber => filled($deal->contract_number),
            self::ContractDate => $deal->contract_date !== null,
            self::Documents => filled($deal->documents),
            self::Prepayment => (float) $deal->prepayment > 0,
            self::PaidInFull => $deal->isPaidInFull(),
        };
    }

    /** Подсказка менеджеру, где именно заполнять. */
    public function hint(): string
    {
        return match ($this) {
            self::ClientPhone, self::ClientEmail, self::ClientAddress,
            self::City, self::CompanyDetails, self::MeasuredAt => 'вкладка «Клиент»',
            self::Doors => 'вкладка «Двери»',
            self::ContractNumber, self::ContractDate, self::Documents => 'вкладка «Договор»',
            self::Prepayment, self::PaidInFull => 'вкладка «Оплата»',
            self::Manager, self::DueDate => 'панель «Управление»',
        };
    }
}
