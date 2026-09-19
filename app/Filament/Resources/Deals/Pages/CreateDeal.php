<?php

declare(strict_types=1);

namespace App\Filament\Resources\Deals\Pages;

use App\Filament\Resources\Deals\DealResource;
use App\Models\Deal;
use App\Services\DoorProductionService;
use Filament\Resources\Pages\CreateRecord;

class CreateDeal extends CreateRecord
{
    protected static string $resource = DealResource::class;

    /**
     * Поля клиента, которые переезжают в повторный заказ со страницы «Клиенты».
     * Адрес объекта — тоже: чаще всего вторая дверь ставится там же.
     */
    private const CLIENT_FIELDS = [
        'client_type', 'source', 'client_name', 'client_phone', 'client_phone_extra',
        'client_email', 'client_company', 'client_bin', 'city', 'client_address',
    ];

    /**
     * `/admin/deals/create?client=<телефон>` — новая сделка для существующего
     * клиента: реквизиты берутся из его последней сделки, менеджер только
     * проверяет их и переходит к дверям.
     */
    protected function fillForm(): void
    {
        parent::fillForm();

        $phone = trim((string) request()->query('client', ''));

        if ($phone === '') {
            return;
        }

        $last = Deal::query()
            ->visibleTo(auth()->user())
            ->sales()
            ->where('client_phone', $phone)
            // id — второй ключ: две сделки, заведённые в одну секунду, иначе сортируются случайно.
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if (! $last) {
            return;
        }

        $data = collect($last->only(self::CLIENT_FIELDS))
            ->filter(fn (mixed $value): bool => filled($value))
            ->map(fn (mixed $value): mixed => $value instanceof \BackedEnum ? $value->value : $value)
            ->all();

        $this->form->fillPartially($data, array_keys($data));
    }

    /**
     * Цена — производная от спецификации, а не поле формы: считаем её сервисом
     * сразу после создания, чтобы в базе не оседали суммы, набранные вручную.
     */
    protected function afterCreate(): void
    {
        app(DoorProductionService::class)->syncPricing($this->record);
    }
}
