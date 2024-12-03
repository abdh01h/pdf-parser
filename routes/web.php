<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers;

Route::controller(Controllers\ParseController::class)->group(function () {
    Route::get('/', 'ParsePDFIndex')->name('home');
    Route::post('/parse1', 'ParsePDFSubmit')->name('parse.pdf.submit1');
    Route::post('/parse2', 'ParsePDFSubmit2')->name('parse.pdf.submit2');
});


