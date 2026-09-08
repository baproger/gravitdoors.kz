<x-filament-panels::page>
    @php
        $breakdown = $this->breakdown();
        $symbol = config('gravit.currency.symbol');
        $money = fn (float $value) => number_format($value, 0, ',', ' ') . ' ' . $symbol;
    @endphp

    <div class="gravit-bento">
        <div class="gravit-tile gravit-calc__form">
            {{ $this->form }}
        </div>

        <div class="gravit-bento gravit-calc__result">
            <div class="gravit-tile gravit-calc__stat">
                <p class="gravit-tile__label">Цена для клиента</p>
                <p class="gravit-tile__value">{{ $money($breakdown->total) }}</p>
                <p class="gravit-tile__hint">
                    {{ $money($breakdown->unitPrice) }} × {{ $breakdown->quantity }} шт
                </p>
            </div>

            <div class="gravit-tile gravit-calc__stat">
                <p class="gravit-tile__label">Себестоимость</p>
                <p class="gravit-tile__value">{{ $money($breakdown->estimatedCost) }}</p>
                <p class="gravit-tile__hint">материалы склада + сдельная оплата цеха</p>
            </div>

            <div class="gravit-tile gravit-calc__stat">
                <p class="gravit-tile__label">Маржа</p>
                <p class="gravit-tile__value">{{ $breakdown->marginPercent() }} %</p>
                <p class="gravit-tile__hint">прибыль {{ $money($breakdown->profit()) }}</p>
            </div>

            <div class="gravit-tile gravit-calc__stat">
                <p class="gravit-tile__label">Габариты</p>
                <p class="gravit-tile__value">{{ $breakdown->areaSqm }} м²</p>
                <p class="gravit-tile__hint">периметр {{ $breakdown->perimeterMeters }} м.п.</p>
            </div>

            <div class="gravit-tile">
                <p class="gravit-tile__label">Расшифровка за 1 изделие</p>

                <div class="gravit-lines" style="margin-top: 0.75rem;">
                    @forelse ($breakdown->lines as $line)
                        <div class="gravit-line">
                            <span>{{ $line['label'] }}</span>
                            <span>{{ $money((float) $line['amount']) }}</span>
                        </div>
                    @empty
                        <p class="gravit-tile__hint">Выберите материалы слева — расчёт появится здесь.</p>
                    @endforelse

                    <div class="gravit-line">
                        <span>Сборка</span>
                        <span>{{ $money($breakdown->assemblyCost) }}</span>
                    </div>

                    @if ($breakdown->markupAmount > 0)
                        <div class="gravit-line">
                            <span>Наценка</span>
                            <span>{{ $money($breakdown->markupAmount) }}</span>
                        </div>
                    @endif

                    <div class="gravit-line gravit-line--total">
                        <span>Цена за изделие</span>
                        <span>{{ $money($breakdown->unitPrice) }}</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-filament-panels::page>
