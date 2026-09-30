<?php

use App\Http\Controllers\ImportLeadsController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::post('/imports', [ImportLeadsController::class, 'import']);
