{{--
    Вход: слева витрина, справа форма.

    Форма целиком — `$this->content` от Filament: поля, кнопка, лимит попыток
    и переход на второй фактор. Здесь только оформление вокруг неё.
--}}
<div class="lg-split">

    <aside class="lg-stage" aria-hidden="true">
        <div class="lg-stage__grid"></div>
        <div class="lg-stage__glow lg-stage__glow--top"></div>
        <div class="lg-stage__glow lg-stage__glow--bottom"></div>

        <div class="lg-stage__inner">
            {{-- Логотип без фона: витрина слева синяя, чёрная плашка на ней
                 читалась бы наклейкой. Подпись рядом — про систему, не про завод. --}}
            <div class="lg-brand">
                <img
                    src="{{ asset('images/gravit-logo-light.png') }}"
                    alt="Gravit — металлические входные двери"
                    class="lg-brand__logo"
                    width="620"
                    height="178"
                />
                <span class="lg-brand__text"><i>ERP · Производство</i></span>
            </div>

            <div class="lg-pitch">
                <h1 class="lg-pitch__title">
                    Управление<br>
                    <em>вашим производством</em>
                </h1>
                <p class="lg-pitch__sub">
                    Сделки, цех, финансы и склад — в одной системе.
                    От заявки до монтажа, без переписки в мессенджерах.
                </p>
            </div>

            <ul class="lg-facts">
                <li class="lg-fact">
                    <span class="lg-fact__value">2</span>
                    <span class="lg-fact__label">воронки: продажи и завод</span>
                </li>
                <li class="lg-fact">
                    <span class="lg-fact__value">13</span>
                    <span class="lg-fact__label">этапов цеха со сдельной оплатой</span>
                </li>
                <li class="lg-fact">
                    <span class="lg-fact__value">QR</span>
                    <span class="lg-fact__label">статус заказа клиенту</span>
                </li>
            </ul>

            <p class="lg-stage__foot">© {{ now()->year }} Gravit · Металлические входные двери</p>
        </div>
    </aside>

    <main class="lg-form" id="fi-main-content" tabindex="-1">
        <div class="lg-form__inner">
            <header class="lg-form__head">
                <h2>Добро пожаловать</h2>
                <p>Войдите в систему, чтобы продолжить</p>
            </header>

            {{ $this->content }}

            <p class="lg-form__foot">Доступ в систему выдаёт директор</p>
        </div>
    </main>

</div>
