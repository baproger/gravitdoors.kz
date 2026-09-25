<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ClientType;
use App\Enums\DealEventType;
use App\Enums\DealSource;
use App\Enums\DealStatus;
use App\Enums\DoorOptionCategory;
use App\Enums\OpeningSide;
use App\Enums\PipelineType;
use App\Enums\TenderLotResult;
use App\Enums\TenderStatus;
use App\Models\Deal;
use App\Models\DealEvent;
use App\Models\DoorConfiguration;
use App\Models\DoorOption;
use App\Models\FactoryStage;
use App\Models\Tender;
use App\Models\TenderLot;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Работа B2B с тендерами: расчёт лота, итоги и сделка из выигранного лота.
 *
 * Сделка из лота — обычная сделка продаж: те же этапы, наряд, склад, оплата.
 * Отличие одно — `contract_price`: цену назначил тендер, и сумма сделки не
 * пересчитывается по прайсу (`DoorProductionService::syncPricing`).
 */
class TenderService
{
    public function __construct(
        private readonly DoorPriceCalculator $calculator,
        private readonly DoorProductionService $production,
    ) {}

    /**
     * Цена лота по прайсу и его себестоимость — по позициям прайса «по умолчанию».
     *
     * Себестоимость как у заказа: материалы на все двери плюс сдельная оплата
     * цеха один раз — по лоту открывается один наряд.
     */
    public function estimate(TenderLot $lot): void
    {
        $breakdown = $this->calculator->calculate([
            'height' => $lot->height,
            'width' => $lot->width,
            'quantity' => $lot->quantity,
            ...$this->defaultCodes(),
        ]);

        $labor = (float) FactoryStage::query()->ofPipeline(PipelineType::Factory)->active()->sum('operation_cost');

        $lot->list_unit_price = $breakdown->unitPrice;
        $lot->estimated_cost = round($breakdown->materialsCost + $labor, 2);
    }

    /**
     * Сделка продаж из выигранного лота.
     *
     * Реквизиты заказчика — из тендера, спецификация — одна позиция с
     * количеством лота, сумма — наша цена по тендеру. Телефон и БИН обязательны:
     * без них карточку сделки потом не сохранить.
     */
    public function createDeal(TenderLot $lot, ?User $actor = null): Deal
    {
        $lot->loadMissing(['tender', 'deal']);
        $tender = $lot->tender;

        if ($lot->hasDeal()) {
            throw ValidationException::withMessages([
                'lot' => 'По этому лоту уже есть сделка'.($lot->deal ? " {$lot->deal->number}" : '').'.',
            ]);
        }

        if ($lot->result !== TenderLotResult::Won) {
            throw ValidationException::withMessages([
                'lot' => 'Сделка заводится только по выигранному лоту — отметьте итог.',
            ]);
        }

        $missing = array_keys(array_filter([
            'наша цена за единицу' => (float) $lot->bid_unit_price <= 0.0,
            'телефон контакта заказчика' => blank($tender->contact_phone),
            'БИН заказчика' => blank($tender->customer_bin),
        ]));

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'lot' => 'Заполните в тендере: '.implode(', ', $missing).'.',
            ]);
        }

        $stage = FactoryStage::firstOf(PipelineType::Sales);

        if (! $stage) {
            throw ValidationException::withMessages([
                'lot' => 'В воронке продаж нет активных этапов — сделку некуда поставить.',
            ]);
        }

        $actor ??= auth()->user();

        return DB::transaction(function () use ($lot, $tender, $stage, $actor): Deal {
            $deal = Deal::create([
                'title' => $this->dealTitle($tender, $lot),
                'client_type' => ClientType::Company,
                'client_company' => $tender->customer_name,
                'client_bin' => $tender->customer_bin,
                'client_name' => $tender->contact_name ?: $tender->customer_name,
                'client_phone' => $tender->contact_phone,
                'client_email' => $tender->contact_email,
                'city' => $tender->city,
                'client_address' => $tender->delivery_address,
                'source' => DealSource::Tender,
                'manager_id' => $tender->manager_id ?? $actor?->id,
                'due_date' => $tender->delivery_due_date ?? now()->addDays(30),
                'status_id' => DealStatus::New,
                'pipeline_type' => PipelineType::Sales,
                'current_stage_id' => $stage->id,
                'contract_price' => $lot->bidTotal(),
                'notes' => 'Из тендера '.$tender->displayName().', '.$lot->displayName().'.',
            ]);

            DoorConfiguration::create([
                'deal_id' => $deal->id,
                'category' => $lot->category,
                'model' => $lot->model,
                'height' => $lot->height,
                'width' => $lot->width,
                'opening_side' => OpeningSide::Right,
                'quantity' => $lot->quantity,
                'comment' => $lot->comment,
                ...$this->defaultColumns(),
            ]);

            $lot->forceFill(['deal_id' => $deal->id])->save();

            $this->production->syncPricing($deal);

            DealEvent::record(
                $deal,
                DealEventType::Tender,
                "Создана из выигранного лота: {$tender->displayName()}, {$lot->displayName()}, {$lot->quantity} шт",
                $actor,
            );

            return $deal->refresh();
        });
    }

    /**
     * Итог тендера по его лотам: хоть один выигран — «Выиграли», все проиграны —
     * «Проиграли». Ставится только из рабочих статусов: «Не участвуем» итоги не
     * перебивают.
     */
    public function syncStatus(Tender $tender): void
    {
        if (! $tender->status->isActive()) {
            return;
        }

        $results = $tender->lots()->pluck('result')
            ->map(fn (mixed $r): TenderLotResult => $r instanceof TenderLotResult ? $r : TenderLotResult::from((string) $r));

        if ($results->isEmpty()) {
            return;
        }

        $status = match (true) {
            $results->contains(TenderLotResult::Won) => TenderStatus::Won,
            $results->every(fn (TenderLotResult $r): bool => $r === TenderLotResult::Lost) => TenderStatus::Lost,
            default => null,
        };

        if ($status !== null) {
            $tender->forceFill(['status' => $status])->save();
        }
    }

    private function dealTitle(Tender $tender, TenderLot $lot): string
    {
        $prefix = filled($tender->announcement_number) ? "Тендер № {$tender->announcement_number}" : 'Тендер';
        $lotPart = filled($lot->lot_number) ? " · лот {$lot->lot_number}" : '';

        return mb_substr("{$prefix}{$lotPart} · {$tender->customer_name}", 0, 255);
    }

    /**
     * Коды позиций прайса «по умолчанию» по категориям — то, что конфигуратор
     * подставил бы в новую дверь сам.
     *
     * @return array<string, string>
     */
    private function defaultCodes(): array
    {
        return DoorOption::query()
            ->active()
            ->where('is_default', true)
            ->whereIn('category', array_map(
                fn (DoorOptionCategory $c): string => $c->value,
                array_filter(DoorOptionCategory::cases(), fn (DoorOptionCategory $c): bool => $c->configurationColumn() !== null),
            ))
            ->orderBy('sort')
            ->get(['category', 'code'])
            ->unique(fn (DoorOption $option): string => $option->category->value)
            ->mapWithKeys(fn (DoorOption $option): array => [$option->category->value => $option->code])
            ->all();
    }

    /** @return array<string, string> колонка двери → код позиции прайса */
    private function defaultColumns(): array
    {
        $columns = [];

        foreach ($this->defaultCodes() as $category => $code) {
            $column = DoorOptionCategory::from($category)->configurationColumn();

            if ($column !== null) {
                $columns[$column] = $code;
            }
        }

        return $columns;
    }
}
