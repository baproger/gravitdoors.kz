{{-- Планшет цеха. Крупные цели нажатия, никаких сумм, автообновление раз в 15 секунд. --}}
<div class="min-h-screen" @if ($authorized) wire:poll.15s @endif>

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
            <div class="mx-auto flex max-w-[1800px] flex-wrap items-center justify-between gap-3 px-5 py-3">
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

        <main class="mx-auto max-w-[1800px] p-5">
            <div class="flex gap-4 overflow-x-auto pb-4">
                @foreach ($this->stages as $stage)
                    <section class="flex w-80 shrink-0 flex-col rounded-2xl border border-slate-200/70 bg-white/60 backdrop-blur-xl dark:border-slate-800 dark:bg-white/5">
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
                                @php($late = $order->hours_on_stage > (float) $stage->estimated_hours && (float) $stage->estimated_hours > 0)

                                <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900">
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
                                            class="flex-1 rounded-xl border border-slate-300 px-3 py-3 text-sm font-semibold text-slate-700 transition active:scale-[0.98] dark:border-slate-600 dark:text-slate-200"
                                        >
                                            Взял
                                        </button>
                                        <button
                                            wire:click="complete({{ $order->id }})"
                                            wire:confirm="Этап «{{ $stage->name }}» выполнен?"
                                            class="flex-[2] rounded-xl bg-emerald-600 px-3 py-3 text-base font-bold text-white transition hover:bg-emerald-700 active:scale-[0.98]"
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
    @endif
</div>
