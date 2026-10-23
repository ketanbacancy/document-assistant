<?php

use Illuminate\Support\Facades\Route;

// Single entry point — React Router (if you add it) handles client-side routes.
// API calls go through routes/api.php under /api/*, untouched by this catch-all.
Route::get('/{any}', function () {
    return view('app');
})->where('any', '.*');
