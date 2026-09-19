{{--
    Плитка инфопанели в bento-сетке.

    layout — от App\Support\BentoLayout: size (s ¼, m ⅓, w ½, l ⅔) и tall для
             планшета, span/rows — точный пролёт на широком экране, уже без дыр.
             На телефоне все плитки во всю ширину.
    tone   — отдел, по нему цвет иконки и мягкий отсвет: sales, money, finance,
             factory, stock, people, mine (личное).
    state  — alert (красная рамка: что-то просрочено) или accent (в работе сейчас).
    href   — плитка целиком становится ссылкой в свой раздел. Внутри такой плитки
             других ссылок нет: вложенные <a> браузер разбирает непредсказуемо.
--}}
@props([
    'label',
    'layout' => ['size' => 's', 'tall' => false, 'span' => 3, 'rows' => 1],
    'tone' => 'sales',
    'state' => null,
    'icon' => null,
    'href' => null,
])

@php($tag = $href ? 'a' : 'article')

<{{ $tag }}
    @if ($href) href="{{ $href }}" @endif
    style="--span: {{ (int) $layout['span'] }}; --rows: {{ (int) $layout['rows'] }};"
    {{ $attributes->class([
        'db-tile',
        'db-tile--'.$layout['size'],
        'db-tile--tall' => $layout['tall'],
        'db-tile--'.$tone,
        'db-tile--'.$state => filled($state),
        'db-tile--link' => filled($href),
    ]) }}
>
    <header class="db-tile__head">
        @if ($icon)
            <span class="db-tile__icon"><x-filament::icon :icon="$icon" /></span>
        @endif
        <span class="db-tile__label">{{ $label }}</span>
        @if ($href)
            <x-filament::icon icon="heroicon-m-arrow-up-right" class="db-tile__go" />
        @endif
    </header>

    {{ $slot }}
</{{ $tag }}>
