{{-- «Новая сделка» в шапке панели: видна на любой странице тому, кто заводит сделки. --}}
@if (\App\Filament\Actions\NewDealAction::allowed())
    <x-filament::button
        tag="a"
        href="{{ \App\Filament\Resources\Deals\DealResource::getUrl('create') }}"
        icon="heroicon-m-plus"
        size="sm"
        class="gravit-topbar-new-deal"
    >
        Новая сделка
    </x-filament::button>
@endif
