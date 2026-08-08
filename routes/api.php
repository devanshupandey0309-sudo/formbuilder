<?php

use App\Http\Controllers\FormController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('/forms', [FormController::class, 'index']);
    Route::post('/forms', [FormController::class, 'store']);
    Route::get('/forms/{form}', [FormController::class, 'show']);
    Route::put('/forms/{form}', [FormController::class, 'update']);
    Route::delete('/forms/{form}', [FormController::class, 'destroy']);
    Route::post('/forms/{form}/publish', [FormController::class, 'publish']);
    Route::post('/forms/{form}/unpublish', [FormController::class, 'unpublish']);
});
