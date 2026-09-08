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
    $canMove = $record && auth()->user()?->can('move', $record);
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
                    <span class="gravit-step__timer">{{ $record->hours_on_stage }} ч</span>
                @endif

                @if ($isNext && $missing !== [])
                    <span class="gravit-step__flag" aria-hidden="true">!</span>
                @elseif ($stage->triggers_production)
                    <span class="gravit-step__flag" aria-hidden="true">→</span>
                @endif
            </button>
        @endforeach
    </div>

    @if ($nextStage && ($blocked = $nextStage->missingFor($record)) !== [])
        <p class="gravit-stepper__hint">
            Для перехода на «{{ $nextStage->name }}» не хватает:
            {{ collect($blocked)->map(fn ($r) => $r->getLabel().' ('.$r->hint().')')->implode('; ') }}
        </p>
    @endif
@endif
