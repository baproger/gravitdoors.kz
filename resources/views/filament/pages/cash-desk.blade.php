{{-- Касса и банк: остатки по счетам сверху, журнал движений снизу. --}}
<x-filament-panels::page>
    @php($accounts = $this->accounts())

    <div class="gravit-bento gravit-bento--compact">
        @forelse ($accounts as $account)
            <div class="gravit-tile gravit-tile--third">
                <p class="gravit-tile__label">{{ $account->name }}</p>
                <p class="gravit-tile__value">{{ \App\Support\Money::format($account->balance()) }}</p>
                <p class="gravit-tile__hint">
                    {{ $account->type->getLabel() }}
                    @if ((float) $account->opening_balance != 0.0)
                        · начальный остаток {{ \App\Support\Money::format($account->opening_balance) }}
                    @endif
                </p>
            </div>
        @empty
            <div class="gravit-tile">
                <p class="gravit-tile__label">Счетов нет</p>
                <p class="gravit-tile__hint">Выполните <code>php artisan db:seed --class=CashAccountSeeder</code> или добавьте счета миграцией — раздел «Счета» в плане финансов.</p>
            </div>
        @endforelse
    </div>

    {{ $this->table }}
</x-filament-panels::page>
