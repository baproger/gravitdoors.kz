{{--
    Полоса этапов воронки. Клик двигает сделку через DoorProductionService —
    та же автоматика, что и на канбане. Вперёд доступен только соседний этап:
    воронку нельзя проскочить, а чего не хватает для перехода — видно в подсказке.
--}}
@php
    $record = $getRecord();
    $stages = $record
        ? \App\Models\FactoryStage::query()
            ->ofPipeline($record->pipeline_type)
            ->active()
            ->ordered()
            ->get()
        : collect();

    $current = $record?->currentStage;
    $currentOrder = $current?->order ?? -1;
    $nextStage = $current?->next();

    // Пока наряд в цеху, сделку ведёт завод: этапы заблокированы, а под полосой
    // объясняется, почему и кто переведёт сделку дальше.
    $waitingOrder = $record && ! $record->isFactoryOrder() ? $record->activeProductionOrder() : null;
    $finishStageName = $waitingOrder
        ? \App\Models\FactoryStage::query()->ofPipeline(\App\Enums\PipelineType::Factory)->where('completes_production', true)->value('name')
        : null;

    $dealClosed = $record && ! $record->isFactoryOrder() && $record->status_id->isClosed();
    $mayMove = $record && (auth()->user()?->can('move', $record) ?? false);
    $canMove = $mayMove && ! $waitingOrder && ! $dealClosed;

    // Наряд на последнем этапе цеха: стрелке вправо некуда вести, наряд
    // закрывается отдельной кнопкой «Готово» — как на планшете цеха.
    $orderClosed = $record?->isFactoryOrder() && $record->status_id->isClosed();
    $canFinish = $canMove && $record->isFactoryOrder() && ! $orderClosed && $current
        && ($current->completes_production || $nextStage === null);
    // Часы по каждому этапу за все заходы — у пройденных видно, сколько они заняли.
    $hoursByStage = $record ? $record->hoursByStage() : [];
    $visits = $record?->visitsOnCurrentStage() ?? 1;
@endphp

@if ($stages->isNotEmpty())
    <div class="gravit-stepper">
        @foreach ($stages as $stage)
            @php
                $isDone = $stage->order < $currentOrder;
                $isCurrent = $record->current_stage_id === $stage->id;
                $isNext = $nextStage?->id === $stage->id;
                // Назад — всегда, вперёд — только на соседний этап.
                $reachable = $canMove && ! $isCurrent && ($isDone || $isNext);
                $missing = $isNext ? $stage->missingFor($record) : [];

                $tooltip = match (true) {
                    $isCurrent => 'Сделка на этом этапе',
                    $dealClosed => 'Сделка закрыта — этапы больше не меняются',
                    ! $mayMove => $record->isFactoryOrder() ? 'Наряд ведёт цех — у вас нет прав его двигать' : 'У вас нет прав двигать сделку',
                    $waitingOrder !== null => 'Сделка ждёт завод — дальше её переведёт производство',
                    $isDone => 'Вернуть сделку на этот этап',
                    $isNext && $missing !== [] => 'Не хватает: '.collect($missing)
                        ->map(fn ($r) => $r->getLabel())->implode(', '),
                    $isNext => 'Перевести сделку сюда',
                    default => 'Сначала пройдите этап «'.($nextStage?->name ?? '—').'»',
                };
            @endphp

            <button
                type="button"
                @if ($reachable)
                    wire:click="moveToStage({{ $stage->id }})"
                    wire:loading.attr="disabled"
                @else
                    disabled
                @endif
                @class([
                    'gravit-step',
                    'gravit-step--done' => $isDone,
                    'gravit-step--current' => $isCurrent,
                    'gravit-step--next' => $isNext,
                    'gravit-step--blocked' => $isNext && $missing !== [],
                    'gravit-step--locked' => ! $reachable && ! $isCurrent,
                ])
                title="{{ $tooltip }}"
            >
                <span class="gravit-step__name">{{ $stage->name }}</span>

                @if ($isCurrent && $record->hours_on_stage >= 1)
                    <span @class(['gravit-step__timer', 'gravit-step__timer--late' => $record->isStageOverdue()])
                          title="{{ trim(($visits > 1 ? $visits.'-й заход, в этот заход '.$record->hoursOnCurrentVisit().' ч, всего на этапе '.$record->hours_on_stage.' ч. ' : 'На этапе '.$record->hours_on_stage.' ч. ').($record->isStageOverdue() ? 'Дольше норматива на '.$record->stageOverdueHours().' ч' : '')) }}">{{ $record->hours_on_stage }} ч{{ $visits > 1 ? ' ↺'.$visits : '' }}</span>
                @elseif (! $isCurrent && ($hoursByStage[$stage->id] ?? 0) >= 1)
                    <span class="gravit-step__timer gravit-step__timer--past" title="Сделка провела на этом этапе {{ $hoursByStage[$stage->id] }} ч">{{ $hoursByStage[$stage->id] }} ч</span>
                @endif

                @if ($isNext && $missing !== [])
                    <span class="gravit-step__flag" aria-hidden="true">!</span>
                @elseif ($stage->triggers_production)
                    <span class="gravit-step__flag" aria-hidden="true">→</span>
                @endif
            </button>
        @endforeach
    </div>

    @if ($canFinish)
        <div class="gravit-stepper__finish">
            <button
                type="button"
                class="gravit-finish"
                wire:click="mountAction('completeStage')"
                wire:loading.attr="disabled"
            >Готово ✓ — закрыть наряд</button>
            <span class="gravit-stepper__finish-note">
                Этап «{{ $current->name }}» будет закрыт{{ $record->parentDeal ? ', сделка '.$record->parentDeal->number.' перейдёт в «Готово к отгрузке»' : '' }}.
            </span>
        </div>
    @elseif ($orderClosed)
        <p class="gravit-stepper__hint gravit-stepper__hint--factory">
            {{ 'Наряд закрыт'.($record->production_finished_at ? ' '.$record->production_finished_at->format('d.m.Y H:i') : '')
                .($record->parentDeal ? ' — сделка '.$record->parentDeal->number.' у отдела продаж.' : '.') }}
        </p>
    @elseif ($dealClosed)
        <p class="gravit-stepper__hint gravit-stepper__hint--factory">
            Сделка {{ $record->status_id === \App\Enums\DealStatus::Cancelled ? 'отменена' : 'завершена' }} — этапы больше не меняются.
        </p>
    @elseif ($record->isFactoryOrder() && ! $mayMove)
        <p class="gravit-stepper__hint gravit-stepper__hint--factory">
            Наряд ведёт цех: этапы закрывают мастер на канбане завода и рабочие на планшете.
        </p>
    @endif

    @if ($record->isShipmentBlocked())
        <p class="gravit-stepper__hint gravit-stepper__hint--overdue">
            Отгрузка заблокирована финансами{{ $record->shipment_block_reason ? ': '.$record->shipment_block_reason : '' }}. Дальше «Передано в производство» сделка не пойдёт, пока блокировку не снимут.
        </p>
    @endif

    @if ($record->isOverdue())
        <p class="gravit-stepper__hint gravit-stepper__hint--overdue">
            Срок сдачи {{ $record->due_date->format('d.m.Y') }} прошёл — просрочка {{ $record->overdueDays() }} {{ \App\Support\Plural::choose($record->overdueDays(), 'день', 'дня', 'дней') }}.
        </p>
    @endif

    @if ($record->isMeasurementOverdue())
        <p class="gravit-stepper__hint gravit-stepper__hint--overdue">
            Замер был назначен на {{ $record->measured_at->format('d.m.Y') }} — прошло {{ $record->measurementOverdueDays() }} {{ \App\Support\Plural::choose($record->measurementOverdueDays(), 'день', 'дня', 'дней') }}, а сделка всё ещё на «{{ $record->currentStage?->name }}». Проведите замер и переведите сделку дальше или назначьте новую дату.
        </p>
    @endif

    @if ($waitingOrder)
        {{-- Фраза собирается целиком: с @if посреди текста перед точкой оставался пробел. --}}
        <p class="gravit-stepper__hint gravit-stepper__hint--factory">
            {{ 'Сделка ждёт завод: наряд '.$waitingOrder->number
                .($waitingOrder->currentStage ? ' сейчас на этапе «'.$waitingOrder->currentStage->name.'»' : '')
                .'. Дальше её переведёт производство'
                .($finishStageName ? ' после «'.$finishStageName.'»' : '')
                .'.' }}
        </p>
    @elseif ($nextStage && ($blocked = $nextStage->missingFor($record)) !== [])
        <p class="gravit-stepper__hint">
            Для перехода на «{{ $nextStage->name }}» не хватает:
            {{ collect($blocked)->map(fn ($r) => $r->getLabel().' ('.$r->hint().')')->implode('; ') }}
        </p>
    @endif
@endif
