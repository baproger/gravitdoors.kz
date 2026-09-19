<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DealStatus;
use App\Enums\PaymentMethod;
use App\Enums\PipelineType;
use App\Enums\UserRole;
use App\Models\Deal;
use App\Models\FactoryStage;
use App\Models\User;
use App\Support\Uploads\PrivateFiles;
use Database\Seeders\FactoryStageSeeder;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Второй слой защиты: файлы не открываются без входа, ответы несут заголовки
 * безопасности, второй фактор доступен каждому в профиле.
 */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([FactoryStageSeeder::class]);
    }

    public function test_uploaded_receipt_is_not_reachable_through_public_storage(): void
    {
        // Настоящий диск, не fake: у подменённого нет маршрута выдачи по подписи.
        $deal = $this->deal();
        $deal->payments()->create(['amount' => 1000, 'method' => PaymentMethod::Kaspi, 'paid_at' => now(), 'receipt_path' => 'receipts/test-secret.pdf']);
        Storage::disk('local')->put('receipts/test-secret.pdf', '%PDF-1.4 secret');
        $this->beforeApplicationDestroyed(fn () => Storage::disk('local')->delete('receipts/test-secret.pdf'));

        // Прямой адрес, как раньше, — ничего не отдаёт: без подписи это 403 или 404.
        $this->assertContains($this->get('/storage/receipts/test-secret.pdf')->status(), [403, 404]);

        // Ссылка из карточки — подписанная и временная, по ней файл открывается.
        $url = $deal->payments()->first()->receiptUrl();
        $this->assertStringContainsString('signature=', $url);
        $this->assertStringContainsString('expires=', $url);
        $this->get($url)->assertOk();

        // Подпись подделать нельзя.
        $this->get(preg_replace('/signature=[^&]+/', 'signature=forged', $url))->assertForbidden();
    }

    public function test_private_url_is_empty_when_there_is_no_file(): void
    {
        $this->assertNull(PrivateFiles::url(null));
        $this->assertNull(PrivateFiles::url(''));
    }

    public function test_every_response_carries_security_headers(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin->value]);

        $response = $this->actingAs($admin)->get('/admin');

        $response->assertOk()
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertStringContainsString('camera=(self)', (string) $response->headers->get('Permissions-Policy'));

        // Публичные страницы — тоже.
        $this->get('/shop')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_hsts_is_sent_only_over_https(): void
    {
        $this->get('/shop')->assertHeaderMissing('Strict-Transport-Security');

        $this->get('https://localhost/shop')
            ->assertOk()
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_two_factor_authentication_is_available_and_shown_in_the_profile(): void
    {
        $this->assertTrue(Filament::getPanel('admin')->hasMultiFactorAuthentication());

        $admin = User::factory()->create(['role' => UserRole::Admin->value]);

        $this->actingAs($admin)->get('/admin/profile')
            ->assertOk()
            ->assertSee('Gravit ERP');

        // Секрет и коды восстановления хранятся зашифрованными.
        $admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $admin->saveAppAuthenticationRecoveryCodes(['alpha-1', 'beta-2']);

        $raw = $admin->getConnection()->table('users')->where('id', $admin->id)->value('app_authentication_secret');
        $this->assertNotSame('JBSWY3DPEHPK3PXP', $raw);
        $this->assertSame('JBSWY3DPEHPK3PXP', $admin->fresh()->getAppAuthenticationSecret());
        $this->assertSame(['alpha-1', 'beta-2'], $admin->fresh()->getAppAuthenticationRecoveryCodes());
    }

    /**
     * Перебор пароля упирается в пятую попытку за минуту.
     *
     * Лимит даёт сам Filament (`Login::authenticate()` → `rateLimit(5)`), своего
     * кода у нас нет — именно поэтому он под тестом: обновление панели может
     * снять защиту молча, а логин директора известен всем, кто видел README.
     */
    /**
     * Перебор пароля упирается в пятую попытку за минуту.
     *
     * Лимит даёт сам Filament (`Login::authenticate()` → `rateLimit(5)`), своего
     * кода у нас нет — именно поэтому он под тестом: обновление панели может
     * снять защиту молча, а логин директора известен всем, кто видел README.
     */
    public function test_login_is_throttled_after_five_wrong_passwords(): void
    {
        $user = User::factory()->create([
            'email' => 'director@gravit.kz',
            'password' => Hash::make('правильный-пароль'),
            'role' => UserRole::Admin->value,
        ]);

        // Сначала убеждаемся, что форма вообще пускает с верным паролем —
        // иначе тест ниже проходил бы и на наглухо сломанном входе.
        Livewire::test(Login::class)
            ->fillForm(['email' => $user->email, 'password' => 'правильный-пароль'])
            ->call('authenticate');

        $this->assertAuthenticatedAs($user);
        auth()->logout();

        $login = Livewire::test(Login::class);

        foreach (range(1, 5) as $attempt) {
            $login->fillForm(['email' => $user->email, 'password' => 'подбор-'.$attempt])
                ->call('authenticate');

            $this->assertFalse(auth()->check(), "Пустили внутрь с неверным паролем, попытка {$attempt}");
        }

        // Шестая: даже верный пароль не пускает, пока лимит не остынет.
        $login->fillForm(['email' => $user->email, 'password' => 'правильный-пароль'])
            ->call('authenticate');

        $this->assertFalse(auth()->check(), 'После пяти попыток вход должен быть заблокирован на минуту');
    }

    private function deal(): Deal
    {
        return Deal::create([
            'title' => 'Сделка',
            'client_name' => 'Клиент',
            'client_phone' => '+7 (700) 000-00-00',
            'total_price' => 1000,
            'due_date' => now()->addWeek(),
            'status_id' => DealStatus::InWork,
            'pipeline_type' => PipelineType::Sales,
            'current_stage_id' => FactoryStage::firstOf(PipelineType::Sales)?->id,
        ]);
    }
}
