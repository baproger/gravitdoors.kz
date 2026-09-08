{{--
    Полоса этапов воронки. Клик по этапу двигает сделку через
    DoorProductionService, то есть запускает ту же автоматику, что и канбан.
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
    $currentOrder = $record?->currentStage?->order ?? -1;
    $canMove = $record && auth()->user()?->can('move', $record);
@endphp

@if ($stages->isNotEmpty())
    <div class="gravit-stepper">
        @foreach ($stages as $stage)
            @php($isDone = $stage->order < $currentOrder)
            @php($isCurrent = $record->current_stage_id === $stage->id)

            <button
                type="button"
                @if ($canMove && ! $isCurrent)
                    wire:click="moveToStage({{ $stage->id }})"
                    wire:loading.attr="disabled"
                @else
                    disabled
                @endif
                @class([
                    'gravit-step',
                    'gravit-step--done' => $isDone,
                    'gravit-step--current' => $isCurrent,
                    'gravit-step--locked' => ! $canMove,
                ])
                title="{{ $stage->description ?: $stage->name }}"
            >
                <span class="gravit-step__name">{{ $stage->name }}</span>

                @if ($isCurrent && $record->hours_on_stage >= 1)
                    <span class="gravit-step__timer">{{ $record->hours_on_stage }} ч</span>
                @endif

                @if ($stage->triggers_production)
                    <span class="gravit-step__flag" title="Создаёт наряд на заводе">→</span>
                @endif
            </button>
        @endforeach
    </div>
@endif
