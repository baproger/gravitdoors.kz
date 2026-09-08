<?php

declare(strict_types=1);

use App\Http\Controllers\TrackController;
use App\Livewire\WorkshopScreen;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

/*
 | Публичное отслеживание заказа по хэшу из QR-кода. Троттлинг здесь не
 | косметика: хэш — единственный секрет страницы, и перебор надо ограничить.
 */
Route::get('/track/{hash}', [TrackController::class, 'show'])
    ->name('track.show')
    ->middleware('throttle:30,1')
    ->where('hash', '[A-Za-z0-9]{8,64}');

/*
 | Планшет цеха. Публичный маршрут: у станка нет логинов и паролей, вход по коду,
 | который выдаёт и меняет администратор в разделе «Экран цеха».
 */
Route::get('/shop', WorkshopScreen::class)->name('workshop.screen');
