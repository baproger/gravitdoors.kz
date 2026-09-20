<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Auth\Login;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Своя страница входа: витрина слева, форма справа.
 *
 * Оформление подменили, логику — нет. Тест держит обе стороны: страница
 * открывается и показывает витрину, а вход по-прежнему пускает внутрь.
 * Сломать вход правкой разметки — значит запереть всех снаружи.
 */
class LoginPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_panel_uses_our_login_page(): void
    {
        $this->assertSame(Login::class, Filament::getPanel('admin')->getLoginRouteAction());
    }

    /**
     * Логотип завода стоит в панели и на входе, и файлы на месте.
     *
     * Ссылку на картинку не ловит ни один обычный тест: страница откроется и с
     * битым изображением, просто на месте знака будет рамка с крестиком.
     * Переименуют файл — узнаем отсюда, а не от сотрудников.
     */
    public function test_brand_logo_is_wired_up_and_the_files_exist(): void
    {
        $panel = Filament::getPanel('admin');

        $files = [
            'светлая тема' => $panel->getBrandLogo(),
            'тёмная тема' => $panel->getDarkModeBrandLogo(),
            'значок вкладки' => $panel->getFavicon(),
        ];

        foreach ($files as $where => $url) {
            $this->assertNotNull($url, "Логотип не задан: {$where}");

            $path = public_path(parse_url((string) $url, PHP_URL_PATH) ?? '');

            $this->assertFileExists($path, "Файл логотипа не найден ({$where})");
            $this->assertGreaterThan(0, filesize($path), "Файл логотипа пуст ({$where})");
        }

        // Тёмная версия — без чёрной плашки: на витрине входа она синяя.
        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('gravit-logo-light.png', escape: false);
    }

    public function test_login_page_opens_for_a_guest_and_shows_the_stage(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('Добро пожаловать')
            ->assertSee('Управление', false)
            ->assertSee('воронки: продажи и завод')
            ->assertSee('этапов цеха со сдельной оплатой')
            ->assertSee('Доступ в систему выдаёт директор');
    }

    /** Поля формы остались филаментовскими — их рисует $this->content. */
    public function test_form_still_has_email_password_and_remember(): void
    {
        Livewire::test(Login::class)
            ->assertFormFieldExists('email')
            ->assertFormFieldExists('password')
            ->assertFormFieldExists('remember');
    }

    public function test_correct_password_still_lets_the_director_in(): void
    {
        $user = User::factory()->create([
            'email' => 'director@gravit.kz',
            'password' => Hash::make('правильный-пароль'),
            'role' => UserRole::Admin->value,
        ]);

        Livewire::test(Login::class)
            ->fillForm(['email' => $user->email, 'password' => 'правильный-пароль'])
            ->call('authenticate');

        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_password_does_not(): void
    {
        User::factory()->create([
            'email' => 'director@gravit.kz',
            'password' => Hash::make('правильный-пароль'),
            'role' => UserRole::Admin->value,
        ]);

        Livewire::test(Login::class)
            ->fillForm(['email' => 'director@gravit.kz', 'password' => 'мимо'])
            ->call('authenticate');

        $this->assertGuest();
    }

    /**
     * Заблокированный сотрудник внутрь не попадает даже с верным паролем.
     *
     * Filament не отдаёт 403, а выкидывает обратно на вход: `canAccessPanel()`
     * у него false. Проверяем именно это — что до панели он не доходит.
     */
    public function test_inactive_employee_cannot_reach_the_panel(): void
    {
        User::factory()->create([
            'email' => 'fired@gravit.kz',
            'password' => Hash::make('правильный-пароль'),
            'role' => UserRole::Manager->value,
            'is_active' => false,
        ]);

        Livewire::test(Login::class)
            ->fillForm(['email' => 'fired@gravit.kz', 'password' => 'правильный-пароль'])
            ->call('authenticate');

        $this->get('/admin')->assertRedirect('/admin/login');
    }
}
