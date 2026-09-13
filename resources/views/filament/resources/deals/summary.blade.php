{{-- Полоса показателей над списком сделок: те же bento-плитки, что у прайса. --}}
<section class="pl-bento dl-summary" aria-label="Сводка по сделкам">
    @foreach ($this->summary() as $tile)
        <div class="pl-tile pl-span-{{ $tile['span'] }} {{ $tile['hero'] ? 'pl-tile--hero' : '' }} {{ $tile['alert'] ? 'dl-tile--alert' : '' }}">
            <p class="pl-tile__label">{{ $tile['label'] }}</p>
            <p class="pl-tile__value">{{ $tile['value'] }}</p>
            <p class="pl-tile__hint">{{ $tile['hint'] }}</p>
        </div>
    @endforeach
</section>
