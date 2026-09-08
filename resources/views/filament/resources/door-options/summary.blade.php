{{-- Bento-сводка над прайсом: сколько позиций и где пусто. --}}
<div class="gravit-bento gravit-bento--compact">
    @foreach ($this->summary() as $tile)
        <div class="gravit-tile gravit-tile--third">
            <p class="gravit-tile__label">{{ $tile['label'] }}</p>
            <p class="gravit-tile__value">{{ $tile['value'] }}</p>
            <p class="gravit-tile__hint">{{ $tile['hint'] }}</p>
        </div>
    @endforeach
</div>
