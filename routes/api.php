<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\FavoritePropertyController;
use App\Http\Controllers\Api\SellerPropertyController;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json(['status' => 'ok']);
});

Route::get('/config/maps', function () {
    return response()->json([
        'google_maps_api_key' => config('services.google_maps.api_key'),
        'default_center' => [
            'label' => 'Dar es Salaam',
            'latitude' => -6.776,
            'longitude' => 39.235,
            'zoom' => 12,
        ],
    ]);
});

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/profile', [AuthController::class, 'profile']);
    Route::put('/profile', [AuthController::class, 'updateProfile']);
    Route::post('/profile/photo', [AuthController::class, 'uploadProfilePhoto']);
    Route::post('/presence', [AuthController::class, 'presence']);
    Route::get('/users', [AuthController::class, 'users']);
});

Route::prefix('conversations')->group(function () {
    Route::get('/', [ConversationController::class, 'index']);
    Route::post('/messages', [ConversationController::class, 'storeMessage']);
    Route::delete('/{conversation}', [ConversationController::class, 'destroy']);
    Route::delete('/messages/{message}', [ConversationController::class, 'destroyMessage']);
});

Route::prefix('seller/properties')->group(function () {
    Route::get('/', [SellerPropertyController::class, 'index']);
    Route::put('/sync', [SellerPropertyController::class, 'sync']);
    Route::put('/{listingId}/location', [SellerPropertyController::class, 'updateLocation']);
    Route::post('/{listingId}/images', [SellerPropertyController::class, 'uploadImages']);
    Route::delete('/{listingId}', [SellerPropertyController::class, 'destroy']);
});

Route::get('/properties', [SellerPropertyController::class, 'published']);
Route::get('/properties/{listingId}', [SellerPropertyController::class, 'show']);

Route::prefix('favorites')->group(function () {
    Route::get('/', [FavoritePropertyController::class, 'index']);
    Route::put('/sync', [FavoritePropertyController::class, 'sync']);
});

