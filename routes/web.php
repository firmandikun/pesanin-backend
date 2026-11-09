<?php

use App\Http\Controllers\LegalController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Public legal pages (required by Meta / Shopee app review).
Route::get('/privacy', [LegalController::class, 'privacy']);
Route::get('/terms', [LegalController::class, 'terms']);
Route::get('/data-deletion', [LegalController::class, 'deletion']);
