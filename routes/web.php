<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\ComparisonController;
use App\Http\Controllers\ComparisonImageController;
use App\Models\Comparison;
use Illuminate\Support\Facades\Route;

// Acceso (sin registro público: los usuarios se crean con `php artisan facematch:user`).
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
});

Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

// Imágenes mediante URL firmada y temporal (API y otro proyecto; no requiere sesión).
Route::get('/imagenes/{comparison}/{side}', ComparisonImageController::class)
    ->whereIn('side', Comparison::IMAGE_SIDES)
    ->middleware('signed')
    ->name('comparisons.image.signed');

// Interfaz web: requiere sesión.
Route::middleware('auth')->group(function () {
    Route::get('/', [ComparisonController::class, 'index'])->name('home');
    Route::get('/historial', [ComparisonController::class, 'history'])->name('comparisons.history');
    Route::post('/comparaciones', [ComparisonController::class, 'store'])->name('comparisons.store');
    Route::get('/comparaciones/{comparison}', [ComparisonController::class, 'show'])->name('comparisons.show');
    Route::get('/comparaciones/{comparison}/imagen/{side}', ComparisonImageController::class)
        ->whereIn('side', Comparison::IMAGE_SIDES)
        ->name('comparisons.image');
    Route::post('/comparaciones/{comparison}/enviar', [ComparisonController::class, 'forward'])->name('comparisons.forward');
    Route::delete('/comparaciones/{comparison}', [ComparisonController::class, 'destroy'])->name('comparisons.destroy');

    Route::get('/registros', [ActivityLogController::class, 'index'])->name('logs.index');
    Route::view('/api-docs', 'docs.api')->name('docs.api');
});
