<!DOCTYPE html>
<html lang="ru" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Заказ {{ $deal->number }} — Gravit</title>
    @vite('resources/css/app.css')
</head>
<body class="min-h-full bg-slate-100 text-slate-900 antialiased dark:bg-slate-950 dark:text-slate-100">

    {{-- Мягкий градиентный фон под «стекло» карточек --}}
    <div class="pointer-events-none fixed inset-0 -z-10 overflow-hidden">
        <div class="absolute -top-40 -left-32 h-96 w-96 rounded-full bg-blue-400/25 blur-3xl dark:bg-blue-600/20"></div>
        <div class="absolute top-1/3 -right-24 h-80 w-80 rounded-full bg-amber-300/25 blur-3xl dark:bg-amber-600/15"></div>
    </div>

    <main class="mx-auto w-full max-w-3xl px-4 py-8 sm:py-12">

        {{-- Шапка --}}
        <header class="mb-6 flex flex-col items-start gap-3 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
            <div>
                <p class="text-xs font-semibold uppercase tracking-widest text-slate-500 dark:text-slate-400">Gravit</p>
                <h1 class="mt-1 text-2xl font-bold sm:text-3xl">Заказ {{ $deal->number }}</h1>
            </div>
            <span @class([
                'shrink-0 rounded-full px-3 py-1.5 text-xs font-semibold ring-1',
                'bg-emerald-500/10 text-emerald-700 ring-emerald-500/20 dark:text-emerald-300' => in_array($deal->status_id->getColor(), ['success', 'primary'], true),
                'bg-amber-500/10 text-amber-700 ring-amber-500/20 dark:text-amber-300' => $deal->status_id->getColor() === 'warning',
                'bg-slate-500/10 text-slate-700 ring-slate-500/20 dark:text-slate-300' => in_array($deal->status_id->getColor(), ['gray', 'info'], true),
                'bg-rose-500/10 text-rose-700 ring-rose-500/20 dark:text-rose-300' => $deal->status_id->getColor() === 'danger',
            ])>
                {{ $deal->status_id->publicLabel() }}
            </span>
        </header>

        <div class="grid gap-4 sm:grid-cols-2">

            {{-- Bento: заказчик --}}
            <section class="rounded-2xl border border-white/60 bg-white/70 p-5 shadow-sm backdrop-blur-xl dark:border-white/10 dark:bg-white/5">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Заказчик</p>
                <p class="mt-2 text-lg font-semibold">{{ $deal->client_name }}</p>
                @if ($deal->client_address)
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $deal->client_address }}</p>
                @endif
                @if ($deal->due_date && ! $deal->status_id->isClosed())
                    <p class="mt-3 text-sm">
                        <span class="text-slate-500 dark:text-slate-400">Плановая готовность:</span>
                        <span class="font-semibold">{{ $deal->due_date->format('d.m.Y') }}</span>
                    </p>
                @endif
            </section>

            {{-- Bento: позиции заказа --}}
            @if ($configurations->isNotEmpty())
                <section class="rounded-2xl border border-white/60 bg-white/70 p-5 shadow-sm backdrop-blur-xl dark:border-white/10 dark:bg-white/5">
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
                        {{ $configurations->count() === 1 ? 'Ваша дверь' : 'Ваши двери' }}
                    </p>

                    <div class="mt-2 space-y-3">
                        @foreach ($configurations as $configuration)
                            <div @class(['border-t border-slate-200 pt-3 dark:border-slate-700' => ! $loop->first])>
                                <p class="text-xs font-medium text-slate-500 dark:text-slate-400">
                                    @if ($configurations->count() > 1)
                                        Позиция {{ $configuration->position }} ·
                                    @endif
                                    {{ $configuration->productName() }}
                                </p>

                                <p class="text-lg font-semibold">{{ $configuration->humanSize() }}</p>

                                <dl class="mt-1 space-y-1 text-sm">
                                    <div class="flex justify-between gap-4">
                                        <dt class="text-slate-500 dark:text-slate-400">Открывание</dt>
                                        <dd class="font-medium">{{ $configuration->opening_side->getLabel() }}</dd>
                                    </div>
                                    <div class="flex justify-between gap-4">
                                        <dt class="text-slate-500 dark:text-slate-400">Количество</dt>
                                        <dd class="font-medium">{{ $configuration->quantity }} шт</dd>
                                    </div>
                                </dl>
                            </div>
                        @endforeach

                        @if ((float) $deal->total_price > 0)
                            <div class="flex justify-between gap-4 border-t border-slate-300 pt-2 text-sm dark:border-slate-600">
                                <span class="text-slate-500 dark:text-slate-400">Сумма заказа</span>
                                <span class="font-bold">{{ number_format((float) $deal->total_price, 0, ',', ' ') }} {{ config('gravit.currency.symbol') }}</span>
                            </div>
                        @endif
                    </div>
                </section>
            @endif

            {{-- Таймлайн сделки --}}
            <section class="rounded-2xl border border-white/60 bg-white/70 p-5 shadow-sm backdrop-blur-xl sm:col-span-2 dark:border-white/10 dark:bg-white/5">
                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Этапы заказа</p>

                @php($currentOrder = $deal->currentStage?->order ?? 0)

                <ol class="mt-4 space-y-0">
                    @foreach ($salesStages as $stage)
                        @php($isDone = $stage->order < $currentOrder)
                        @php($isCurrent = $deal->currentStage && $stage->is($deal->currentStage))

                        <li class="relative flex gap-4 pb-6 last:pb-0">
                            {{-- Соединительная линия --}}
                            @unless ($loop->last)
                                <span @class([
                                    'absolute left-[11px] top-6 h-full w-px',
                                    'bg-emerald-400 dark:bg-emerald-500' => $isDone,
                                    'bg-slate-200 dark:bg-slate-700' => ! $isDone,
                                ])></span>
                            @endunless

                            <span @class([
                                'relative z-10 mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[11px] font-bold ring-4 ring-white dark:ring-slate-950',
                                'bg-emerald-500 text-white' => $isDone,
                                'bg-blue-600 text-white' => $isCurrent,
                                'bg-slate-200 text-slate-400 dark:bg-slate-700 dark:text-slate-500' => ! $isDone && ! $isCurrent,
                            ])>
                                @if ($isDone) ✓ @else {{ $loop->iteration }} @endif
                            </span>

                            <div class="min-w-0 flex-1">
                                <p @class([
                                    'text-sm font-semibold',
                                    'text-slate-900 dark:text-slate-100' => $isDone || $isCurrent,
                                    'text-slate-400 dark:text-slate-500' => ! $isDone && ! $isCurrent,
                                ])>{{ $stage->name }}</p>

                                @if ($isCurrent)
                                    <p class="mt-0.5 text-xs text-blue-600 dark:text-blue-400">выполняется сейчас</p>
                                @endif

                                {{-- Прогресс цеха разворачивается внутри своего этапа --}}
                                @if ($isCurrent && $stage->triggers_production && $order)
                                    <div class="mt-3 rounded-xl border border-slate-200/70 bg-white/60 p-3 dark:border-slate-700/60 dark:bg-slate-900/40">
                                        <p class="text-xs font-semibold text-slate-500 dark:text-slate-400">На производстве</p>
                                        <div class="mt-2 flex flex-wrap gap-1.5">
                                            @foreach ($factoryStages as $fStage)
                                                @php($fDone = $order->currentStage && $fStage->order < $order->currentStage->order)
                                                @php($fCurrent = $order->currentStage && $fStage->is($order->currentStage))
                                                <span @class([
                                                    'rounded-lg px-2 py-1 text-[11px] font-medium ring-1',
                                                    'bg-emerald-500/10 text-emerald-700 ring-emerald-500/20 dark:text-emerald-300' => $fDone,
                                                    'bg-blue-600/10 text-blue-700 ring-blue-600/20 dark:text-blue-300' => $fCurrent,
                                                    'bg-slate-500/5 text-slate-400 ring-slate-500/10 dark:text-slate-500' => ! $fDone && ! $fCurrent,
                                                ])>
                                                    @if ($fDone) ✓ @endif {{ $fStage->name }}
                                                </span>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ol>
            </section>

            {{-- QR + контакты --}}
            <section class="rounded-2xl border border-white/60 bg-white/70 p-5 text-center shadow-sm backdrop-blur-xl sm:col-span-2 dark:border-white/10 dark:bg-white/5">
                <div class="inline-block rounded-xl bg-white p-3 shadow-sm">
                    {{ app(\App\Services\QrCodeService::class)->svg($deal->track_url, 160) }}
                </div>
                <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">
                    Сохраните этот QR-код — по нему всегда открывается актуальный статус заказа.
                </p>
            </section>
        </div>

        <footer class="mt-8 text-center text-xs text-slate-400 dark:text-slate-600">
            Обновлено {{ $deal->updated_at?->format('d.m.Y H:i') }} · Gravit
        </footer>
    </main>
</body>
</html>
