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

    $canMove = $record && ! $waitingOrder && auth()->user()?->can('move', $record);
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
                          title="{{ trim(($visits > 1 ? $visits.'-й заход, в этот заход '.$record->hoursOnCurrentVisit().' ч, всего на этапе '.$record->hours_on_stage.' ч. ' : 'На этапе '.$record->hours_on_stage.' ч. ').($record->isStageOverdue() ? 'Дольше норматива на '.$record->stageOverdueHours().' ч' : '')) }}">{{ $record->hours_on_stage }} ч{{ $visits > 1 ? ' · '.$visits.'-й заход' : '' }}</span>
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
