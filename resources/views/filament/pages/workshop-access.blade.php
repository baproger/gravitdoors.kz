<x-filament-panels::page>
    <div class="gravit-bento">
        <div class="gravit-tile gravit-tile--half">
            <p class="gravit-tile__label">Код доступа</p>
            <p class="gravit-tile__value" style="letter-spacing: 0.25em;">{{ $code }}</p>
            <p class="gravit-tile__hint">Введите его на планшете один раз — дальше вход запоминается.</p>
        </div>

        <div class="gravit-tile gravit-tile--half">
            <p class="gravit-tile__label">Адрес экрана</p>
            <p class="gravit-tile__value" style="font-size: 1.1rem; word-break: break-all;">{{ $this->screenUrl() }}</p>
            <p class="gravit-tile__hint">
                <a href="{{ $this->screenUrl() }}" target="_blank" style="color: rgb(47 111 237); font-weight: 600;">
                    Открыть в новой вкладке →
                </a>
            </p>
        </div>

        <div class="gravit-tile">
            <p class="gravit-tile__label">Что видит цех</p>
            <div class="gravit-lines" style="margin-top: 0.75rem;">
                <div class="gravit-line"><span>Наряды по этапам своего цеха</span><span>да</span></div>
                <div class="gravit-line"><span>Габариты, сторона открывания, комментарий для цеха</span><span>да</span></div>
                <div class="gravit-line"><span>Кнопки «Взял» и «Готово ✓»</span><span>да</span></div>
                <div class="gravit-line"><span>Суммы, себестоимость, маржа</span><span>нет</span></div>
                <div class="gravit-line"><span>Данные клиента и телефон</span><span>нет</span></div>
            </div>
        </div>
    </div>
</x-filament-panels::page>
