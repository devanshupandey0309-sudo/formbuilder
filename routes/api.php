<?php

use App\Http\Controllers\AIFormController;
use App\Http\Controllers\FieldController;
use App\Http\Controllers\FormController;
use App\Http\Controllers\FormImportController;
use App\Http\Controllers\PublicFormController;
use App\Http\Controllers\SectionController;
use Illuminate\Support\Facades\Route;

Route::prefix('public')->group(function () {
    Route::get('/forms/{slug}', [PublicFormController::class, 'show']);
    Route::post('/forms/{slug}/submit', [PublicFormController::class, 'submit']);
});

Route::middleware('auth')->scopeBindings()->group(function () {
    Route::get('/forms', [FormController::class, 'index']);
    Route::post('/forms', [FormController::class, 'store']);
    Route::get('/forms/{form}', [FormController::class, 'show']);
    Route::put('/forms/{form}', [FormController::class, 'update']);
    Route::delete('/forms/{form}', [FormController::class, 'destroy']);
    Route::post('/forms/{form}/publish', [FormController::class, 'publish']);
    Route::post('/forms/{form}/unpublish', [FormController::class, 'unpublish']);

    Route::post('/forms/{form}/ai/generate', [AIFormController::class, 'generate']);
    Route::get('/forms/{form}/ai/jobs/{aiJob}', [AIFormController::class, 'show']);
    Route::post('/forms/{form}/ai/jobs/{aiJob}/apply', [AIFormController::class, 'apply']);

    Route::post('/forms/{form}/imports', [FormImportController::class, 'store']);
    Route::get('/forms/{form}/imports/{formImport}', [FormImportController::class, 'show']);
    Route::get('/forms/{form}/imports/{formImport}/preview', [FormImportController::class, 'preview']);
    Route::post('/forms/{form}/imports/{formImport}/commit', [FormImportController::class, 'commit']);

    Route::post('/forms/{form}/sections/reorder', [SectionController::class, 'reorder']);
    Route::post('/forms/{form}/sections', [SectionController::class, 'store']);
    Route::put('/forms/{form}/sections/{section}', [SectionController::class, 'update']);
    Route::delete('/forms/{form}/sections/{section}', [SectionController::class, 'destroy']);

    Route::post('/forms/{form}/sections/{section}/fields/reorder', [FieldController::class, 'reorder']);
    Route::post('/forms/{form}/sections/{section}/fields', [FieldController::class, 'store']);
    Route::put('/forms/{form}/sections/{section}/fields/{field}', [FieldController::class, 'update']);
    Route::delete('/forms/{form}/sections/{section}/fields/{field}', [FieldController::class, 'destroy']);
});
