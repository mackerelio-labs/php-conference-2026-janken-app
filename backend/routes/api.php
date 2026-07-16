<?php

use App\Http\Controllers\GameController;
use App\Http\Controllers\HandController;
use Illuminate\Support\Facades\Route;

// API 仕様は docs/endpoints.md を参照
Route::post('/games', [GameController::class, 'store']);
Route::post('/games/{game}/hands', [HandController::class, 'store']);
Route::post('/games/{game}/judge', [GameController::class, 'judge']);
Route::get('/health', fn () => response('ok'));
