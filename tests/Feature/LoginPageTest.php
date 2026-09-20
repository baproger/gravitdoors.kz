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
     * Знак завода стоит и в панели, и на входе, а файлы под ним существуют.
     *
     * Битую картинку не ловит ни один обычный тест: страница откроется, просто
     * вместо знака будет рамка с крестиком. Переименуют файл — узнаем отсюда,
     * а не от сотрудников.
     */
    public function test_brand_is_wired_up_and_its_files_exist(): void
    {
        foreach (['gravit-icon.png', 'gravit-logo-light.png', 'gravit-favicon.png'] as $file) {
            $path = public_path('images/'.$file);

            $this->assertFileExists($path, "Нет файла знака: {$file}");
            $this->assertGreaterThan(0, filesize($path), "Файл знака пуст: {$file}");
        }

        // Значок вкладки задан адресом, знак сайдбара — разметкой.
        $this->assertStringContainsString(
            'gravit-favicon.png',
            (string) Filament::getPanel('admin')->getFavicon()
        );

        // Витрина входа синяя — там знак без чёрной плашки.
        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('gravit-logo-light.png', escape: false);
    }

    /** В сайдбаре — круглая иконка и название, а не широкая картинка с надписью. */
    public function test_sidebar_brand_is_a_round_mark_with_the_name(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin->value]);

        $this->actingAs($admin)->get('/admin')
            ->assertOk()
            ->assertSee('gv-brand__mark', escape: false)
            ->assertSee('gravit-icon.png', escape: false)
            ->assertSee('Gravit');
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
