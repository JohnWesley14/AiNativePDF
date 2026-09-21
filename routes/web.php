<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PdfController;
use App\Http\Controllers\ChatController;

Route::get('/', function () {
    return view('welcome');
});

// Rotas de Processamento de PDF
Route::post('/api/upload-lote', [PdfController::class, 'uploadLote']);
Route::get('/api/processar-item/{id}', [PdfController::class, 'processarItem']);
Route::post('/api/processa-pdf', [PdfController::class, 'processaPdf']);

// Rotas de IA e Streaming
Route::post('/api/chat', [ChatController::class, 'chat']);
Route::post('/api/stream', [ChatController::class, 'stream']);