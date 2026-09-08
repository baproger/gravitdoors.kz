<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Администратор — глобальный суперпользователь: иначе каждую новую
        // политику пришлось бы отдельно учить пропускать админа.
        Gate::before(fn (User $user) => $user->isAdmin() ? true : null);
    }
}
