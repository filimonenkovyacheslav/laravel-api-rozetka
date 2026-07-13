<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\OrderAdminController;
use App\Models\Order;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

Route::get('/', function () {
    return view('home');
});

Route::middleware(['web', 'admin.basic'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/orders', [OrderAdminController::class, 'index'])->name('orders.index');
    Route::get('/orders/{order}', [OrderAdminController::class, 'show'])->name('orders.show');

    // Edit order details
    Route::get('/orders/{order}/edit', [OrderAdminController::class, 'edit'])->name('orders.edit');
    Route::post('/orders/{order}', [OrderAdminController::class, 'update'])->name('orders.update');

    Route::post('/orders/{order}/cancel', [OrderAdminController::class, 'cancel'])->name('orders.cancel');

    // Fallback if someone opens /ship directly via browser GET
    Route::get('/orders/{order}/ship', function (Order $order) {
        return redirect()
            ->route('admin.orders.show', $order)
            ->with('err', 'ТТН потрібно зберігати через кнопку “Відвантажити”.');
    })->name('orders.ship.get');

    Route::post('/orders/{order}/ship', [OrderAdminController::class, 'ship'])->name('orders.ship');

    // Edit only reserved_quantity
    Route::get('/order-items/{item}/edit', [OrderAdminController::class, 'editItem'])->name('items.edit');
    Route::post('/order-items/{item}', [OrderAdminController::class, 'updateItem'])->name('items.update');

    // Files
    Route::get('/order-files/{file}/download', [OrderAdminController::class, 'downloadFile'])->name('files.download');
    Route::post('/order-files/{file}/delete', [OrderAdminController::class, 'deleteFile'])->name('files.delete');
});

Route::get('/home', [App\Http\Controllers\HomeController::class, 'index'])->name('home');