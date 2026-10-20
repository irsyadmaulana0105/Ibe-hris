<?php

use Illuminate\Support\Facades\Route;

Route::get('/ai/modules/{module}/summary', \App\Http\Controllers\Api\V1\AiModuleSummaryController::class)
    ->middleware('throttle:30,1');
