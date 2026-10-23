<?php

use App\Http\Controllers\ChatController;
use App\Http\Controllers\DocumentController;
use Illuminate\Support\Facades\Route;

Route::get('/document', [DocumentController::class, 'show']);
Route::get('/documents', [DocumentController::class, 'index']);

Route::post('/chat', [ChatController::class, 'ask']);
Route::post('/chat/stream', [ChatController::class, 'askStream']);
Route::get('/chat/{sessionUuid}/history', [ChatController::class, 'history']);
Route::get('/conversations', [ChatController::class, 'conversations']);
