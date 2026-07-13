<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\OrderController;

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
Route::post('/ping', function () {
    return response()->json(['pong' => true]);
});

Route::middleware('api.key')->group(function () {
    Route::get('/orders/list', [OrderController::class, 'list']);
    Route::get('/orders',      [OrderController::class, 'index']);
    Route::get('/order/{guid}',[OrderController::class, 'show']);

    Route::post('/order/create',        [OrderController::class, 'create']);
    Route::put('/order/edit/{guid}',    [OrderController::class, 'edit']);
    Route::delete('/order/cancel/{guid}', [OrderController::class, 'cancel']);
    Route::get('/order/status/{guid}',  [OrderController::class, 'status']);

    Route::post('/order/{guid}/upload', [OrderController::class, 'upload']);
    Route::delete('/file/{file_guid}/delete', [OrderController::class, 'deleteFile']);
});

Route::middleware('auth:api')->get('/user', function (Request $request) {
    return $request->user();
});
