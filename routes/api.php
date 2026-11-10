<?php

use App\Http\Controllers\Api\FaceCompareController;
use Illuminate\Support\Facades\Route;

Route::middleware(['face.log', 'face.token', 'throttle:face-api'])
    ->prefix('face')
    ->name('api.face.')
    ->group(function () {
        Route::get('/health', [FaceCompareController::class, 'health'])->name('health');
        Route::post('/compare', [FaceCompareController::class, 'store'])->name('compare');
        Route::get('/comparisons', [FaceCompareController::class, 'index'])->name('comparisons.index');
        Route::get('/comparisons/{comparison}', [FaceCompareController::class, 'show'])->name('comparisons.show');
    });
