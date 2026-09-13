{{-- Просроченные: переключатель «срок сдачи / застряли на этапе» и таблица, самая большая просрочка сверху. --}}
<x-filament-panels::page>
    <div class="od">
        <div class="gp-tabs" role="tablist" aria-label="Вид просрочки">
            @foreach ($this->modes() as $key => $tab)
                <button
                    type="button"
                    role="tab"
                    wire:click="$set('mode', @js($key))"
                    aria-selected="{{ $mode === $key ? 'true' : 'false' }}"
                    class="gp-tab {{ $mode === $key ? 'gp-tab--active' : '' }}"
                >
                    <span>{{ $tab['label'] }}</span>
                    <span class="gp-tab__count {{ $tab['count'] > 0 ? 'od-count--hot' : '' }}">{{ $tab['count'] }}</span>
                </button>
            @endforeach
        </div>

        {{ $this->table }}
    </div>
</x-filament-panels::page>
