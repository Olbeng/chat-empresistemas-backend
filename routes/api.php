<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\WhatsAppWebhookController;
use App\Http\Controllers\SecureDownloadController;


// Route::get('/user', function (Request $request) {
//     return $request->user();
// })->middleware('auth:sanctum');

Route::post('login', [AuthController::class, 'login']);
Route::post('refresh', [AuthController::class, 'refresh']);
Route::post('logout', [AuthController::class, 'logout']);

// Ruta para obtener token de Ably
Route::middleware('jwt.auth')->get('/ably/token', [MessageController::class, 'getAblyToken']);


// Rutas para webhooks del ERP
Route::post('/erp-webhook/message-created', [MessageController::class, 'handleERPMessageCreated']);
Route::post('/erp-webhook/message-updated', [MessageController::class, 'handleERPMessageUpdated']);

// Rutas para el webhook de WhatsApp (sin usar prefix)
Route::get('/whatsapp-webhook', [WhatsAppWebhookController::class, 'verify']);
Route::post('/whatsapp-webhook', [WhatsAppWebhookController::class, 'handleWebhook']);

// Protege las rutas de contactos con JWT
Route::middleware(['jwt.auth'])->group(function () {
    // Obtener todos los contactos de un usuario
    Route::get('/contacts/{userId}', [ContactController::class, 'index']);
    Route::get('/secure-download',  [SecureDownloadController::class, 'downloadFile']);
});
Route::group(['middleware' => 'jwt.auth'], function () {
    Route::post('/messages/send', [MessageController::class, 'send']);
    Route::post('/messages/send-file', [MessageController::class, 'sendFile']);

    Route::get('/messages/{contactId}', [MessageController::class, 'getMessages']);
    Route::patch('/messages/updateMessageStatus/{contactId}', [MessageController::class, 'updateMessageStatus']);
    Route::get('/batch-messages', [MessageController::class, 'getBatchInitialMessages']);
});
