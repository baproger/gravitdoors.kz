<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PipelineType;
use App\Models\Deal;
use App\Models\FactoryStage;
use Illuminate\Contracts\View\View;

/**
 * Публичная страница статуса заказа. Единственный ключ доступа — qr_code_hash
 * из ссылки/QR-кода, поэтому страница не показывает себестоимость, маржу
 * и внутренние заметки: клиенту видно только то, что касается его двери.
 */
class TrackController extends Controller
{
    public function show(string $hash): View
    {
        $deal = Deal::query()
            ->where('qr_code_hash', $hash)
            ->with(['doorConfigurations', 'currentStage', 'productionOrder.currentStage', 'productionOrder.productionLogs.stage'])
            ->firstOrFail();

        // По QR наряда клиент должен попадать на свою сделку, а не на внутренний наряд.
        $salesDeal = $deal->salesDeal();

        if ($salesDeal->isNot($deal)) {
            $salesDeal->load(['doorConfigurations', 'currentStage', 'productionOrder.currentStage']);
        }

        $order = $salesDeal->productionOrder;

        return view('track.show', [
            'deal' => $salesDeal,
            'configurations' => $salesDeal->doorConfigurations,
            'order' => $order,
            'salesStages' => FactoryStage::query()->ofPipeline(PipelineType::Sales)->active()->ordered()->get(),
            'factoryStages' => $order
                ? FactoryStage::query()->ofPipeline(PipelineType::Factory)->active()->ordered()->get()
                : collect(),
        ]);
    }
}
