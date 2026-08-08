<?php

use App\Livewire\Forms\FormBuilder;
use App\Livewire\Forms\FormIndex;
use App\Livewire\Forms\FormPreview;
use App\Livewire\Forms\PublicForm;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');

Route::get('/f/{slug}', PublicForm::class)->name('forms.public');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    Route::get('/forms', FormIndex::class)->name('forms.index');
    Route::get('/forms/{form}/builder', FormBuilder::class)->name('forms.builder');
    Route::get('/forms/{form}/preview', FormPreview::class)->name('forms.preview');
});

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

require __DIR__.'/auth.php';
