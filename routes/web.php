<?php

use Illuminate\Support\Facades\Route;

// Página principal ( index)
Route::get('/', function () {
    return view('index');
});

