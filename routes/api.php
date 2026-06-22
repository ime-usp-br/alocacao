<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AllocationProgressWebhookController;
use App\Http\Controllers\AllocationResultWebhookController;
use App\Http\Controllers\ComparisonResultWebhookController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::post('/webhooks/allocation-progress', AllocationProgressWebhookController::class)
    ->name('webhooks.allocation.progress');

Route::post('/webhooks/allocation-result', AllocationResultWebhookController::class)
    ->name('webhooks.allocation.result');

Route::post('/webhooks/comparison-result', ComparisonResultWebhookController::class)
    ->name('webhooks.comparison.result');
