{{-- Планшет цеха. Крупные цели нажатия, никаких сумм, автообновление раз в 15 секунд. --}}
<div class="min-h-screen" @if ($authorized) wire:poll.15s @endif
     x-data="{ confirmId: null, confirmStage: '', confirmTitle: '', confirmLast: false }"
     x-on:keydown.escape.window="confirmId = null">

    @if (! $authorized)
        {{-- Вход по коду --}}
        <div class="flex min-h-screen items-center justify-center p-6">
            <form wire:submit="enter" class="w-full max-w-sm rounded-3xl border border-white/60 bg-white/80 p-8 shadow-lg backdrop-blur-xl dark:border-white/10 dark:bg-white/5">
                <p class="text-center text-xs font-semibold uppercase tracking-widest text-slate-500 dark:text-slate-400">Gravit</p>
                <h1 class="mt-1 text-center text-2xl font-bold">Экран цеха</h1>

                <input
                    type="text"
                    inputmode="numeric"
                    autocomplete="off"
                    maxlength="6"
                    wire:model="code"
                    placeholder="000000"
                    class="mt-6 w-full rounded-2xl border border-slate-300 bg-white px-4 py-4 text-center text-3xl font-bold tracking-[0.4em] text-slate-900 focus:border-blue-500 focus:ring-4 focus:ring-blue-500/20 focus:outline-none dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100"
                >

                @if ($error)
                    <p class="mt-3 text-center text-sm font-medium text-rose-600 dark:text-rose-400">{{ $error }}</p>
                @endif

                <button type="submit" class="mt-5 w-full rounded-2xl bg-blue-600 px-4 py-4 text-lg font-semibold text-white transition hover:bg-blue-700 active:scale-[0.99]">
                    Войти
                </button>
            </form>
        </div>
    @else
        <header class="sticky top-0 z-10 border-b border-slate-200/70 bg-white/80 backdrop-blur-xl dark:border-slate-800 dark:bg-slate-950/80">
            <div class="mx-auto flex max-w-[1800px] flex-wrap items-center justify-between gap-2 px-3 py-2 sm:gap-3 sm:px-5 sm:py-3">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-widest text-slate-500 dark:text-slate-400">Gravit</p>
                    <h1 class="text-xl font-bold">Цех</h1>
                </div>

                <div class="flex items-center gap-2">
                    {{-- Кто работает: без этого сдельная оплата не на кого записаться --}}
                    <select
                        wire:change="chooseWorker($event.target.value)"
                        class="rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm font-medium dark:border-slate-600 dark:bg-slate-900"
                    >
                        <option value="">Кто работает?</option>
                        @foreach ($this->workers as $worker)
                            <option value="{{ $worker->id }}" @selected($workerId === $worker->id)>{{ $worker->name }}</option>
                        @endforeach
                    </select>

                    <button wire:click="leave" class="rounded-xl border border-slate-300 px-3 py-2 text-sm font-medium text-slate-500 dark:border-slate-600">
                        Выйти
                    </button>
                </div>
            </div>

            @if ($error)
                <p class="bg-rose-50 px-5 py-2 text-sm font-medium text-rose-700 dark:bg-rose-950/40 dark:text-rose-300">{{ $error }}</p>
            @endif
        </header>

        <main class="mx-auto max-w-[1800px] p-3 sm:p-5">
            <div class="flex snap-x snap-mandatory gap-3 overflow-x-auto pb-4 sm:gap-4">
                @foreach ($this->stages as $stage)
                    <section class="flex w-[85vw] shrink-0 snap-start flex-col rounded-2xl sm:w-80 border border-slate-200/70 bg-white/60 backdrop-blur-xl dark:border-slate-800 dark:bg-white/5">
                        <header class="border-b border-slate-200/70 px-4 py-3 dark:border-slate-800">
                            <div class="flex items-center justify-between gap-2">
                                <h2 class="text-base font-bold">{{ $stage->name }}</h2>
                                <span class="rounded-full bg-slate-200/70 px-2.5 py-0.5 text-sm font-bold text-slate-600 dark:bg-slate-700 dark:text-slate-300">
                                    {{ $stage->deals_count ?? $stage->deals->count() }}
                                </span>
                            </div>
                            @if ((float) $stage->estimated_hours > 0)
                                <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                                    норматив {{ rtrim(rtrim((string) $stage->estimated_hours, '0'), '.') }} ч
                                </p>
                            @endif
                        </header>

                        <div class="flex flex-col gap-3 p-3">
                            @forelse ($stage->deals as $order)
                                @php($configs = $order->configurations())
                                @php($late = $order->isStageOverdue())

                                <article @class([
                                    'rounded-xl border bg-white p-4 shadow-sm dark:bg-slate-900',
                                    'border-rose-400 ring-2 ring-rose-200 dark:border-rose-500 dark:ring-rose-900' => $order->isOverdue(),
                                    'border-slate-200 dark:border-slate-700' => ! $order->isOverdue(),
                                ])>
                                    @if ($order->isOverdue())
                                        <p class="mb-2 rounded-lg bg-rose-50 px-2 py-1 text-xs font-bold text-rose-700 dark:bg-rose-900/40 dark:text-rose-300">
                                            Срок сдачи прошёл — просрочка {{ $order->overdueDays() }} дн.
                                        </p>
                                    @endif
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="text-xs font-bold tracking-wide text-slate-400">{{ $order->number }}</span>
                                        <span @class([
                                            'text-xs font-bold',
                                            'text-rose-600 dark:text-rose-400' => $late,
                                            'text-slate-400' => ! $late,
                                        ])>⏱ {{ $order->hours_on_stage }} ч</span>
                                    </div>

                                    <h3 class="mt-1 text-lg leading-tight font-bold">{{ $order->parentDeal?->title ?? $order->title }}</h3>

                                    @foreach ($configs as $config)
                                        <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">
                                            {{ $config->humanSize() }} · {{ $config->opening_side->getLabel() }} · {{ $config->quantity }} шт
                                            @if ($config->comment)
                                                <span class="mt-0.5 block text-amber-700 dark:text-amber-400">⚠ {{ $config->comment }}</span>
                                            @endif
                                        </p>
                                    @endforeach

                                    <div class="mt-3 flex gap-2">
                                        <button
                                            wire:click="start({{ $order->id }})"
                                            class="min-h-12 flex-1 rounded-xl border border-slate-300 px-3 text-sm font-semibold text-slate-700 transition active:scale-[0.98] dark:border-slate-600 dark:text-slate-200"
                                        >
                                            Взял
                                        </button>
                                        {{-- Подтверждение — своя модалка ниже, а не системное окно браузера: оно мелкое и не в стиле экрана. --}}
                                        <button
                                            type="button"
                                            x-on:click="confirmId = {{ $order->id }}; confirmStage = @js($stage->name); confirmTitle = @js($order->parentDeal?->title ?? $order->title); confirmLast = @js((bool) ($stage->completes_production || $stage->is_final))"
                                            class="min-h-12 flex-[2] rounded-xl bg-emerald-600 px-3 text-base font-bold text-white transition hover:bg-emerald-700 active:scale-[0.98]"
                                        >
                                            Готово ✓
                                        </button>
                                    </div>
                                </article>
                            @empty
                                <p class="rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-400 dark:border-slate-700">
                                    Пусто
                                </p>
                            @endforelse
                        </div>
                    </section>
                @endforeach
            </div>
        </main>

        {{-- Подтверждение «Готово ✓»: крупные кнопки под палец, закрывается по фону и Esc. --}}
        <div
            x-show="confirmId !== null"
            x-cloak
            x-transition.opacity.duration.150ms
            class="fixed inset-0 z-50 flex items-end justify-center bg-slate-900/50 p-4 backdrop-blur-sm sm:items-center"
            x-on:click.self="confirmId = null"
            role="dialog"
            aria-modal="true"
            aria-labelledby="workshop-confirm-title"
        >
            <div
                x-show="confirmId !== null"
                x-transition:enter="transition duration-200 ease-out"
                x-transition:enter-start="translate-y-4 opacity-0 sm:translate-y-0 sm:scale-95"
                x-transition:enter-end="translate-y-0 opacity-100 sm:scale-100"
                class="w-full max-w-md rounded-3xl border border-white/60 bg-white p-6 shadow-2xl dark:border-white/10 dark:bg-slate-900"
            >
                <div class="flex items-start gap-4">
                    <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-emerald-100 text-2xl text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300">✓</span>
                    <div class="min-w-0">
                        <h2 id="workshop-confirm-title" class="text-xl leading-tight font-bold">
                            Этап «<span x-text="confirmStage"></span>» выполнен?
                        </h2>
                        <p class="mt-1 truncate text-sm text-slate-500 dark:text-slate-400" x-text="confirmTitle"></p>
                        <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">
                            <template x-if="confirmLast"><span>Наряд закроется и уйдёт отделу продаж. Оплата за этап запишется на выбранного исполнителя.</span></template>
                            <template x-if="! confirmLast"><span>Наряд перейдёт на следующий этап. Оплата за этот этап запишется на выбранного исполнителя.</span></template>
                        </p>
                    </div>
                </div>

                <div class="mt-6 flex gap-3">
                    <button
                        type="button"
                        x-on:click="confirmId = null"
                        class="min-h-14 flex-1 rounded-2xl border border-slate-300 px-4 text-base font-semibold text-slate-700 transition active:scale-[0.98] dark:border-slate-600 dark:text-slate-200"
                    >
                        Отмена
                    </button>
                    <button
                        type="button"
                        x-on:click="$wire.complete(confirmId); confirmId = null"
                        class="min-h-14 flex-[2] rounded-2xl bg-emerald-600 px-4 text-lg font-bold text-white transition hover:bg-emerald-700 active:scale-[0.98]"
                    >
                        Да, готово ✓
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
