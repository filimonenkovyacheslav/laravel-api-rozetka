<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\OrderAdminController;
use App\Models\Order;
use App\Http\Controllers\Admin\ProductDimensionController;
use App\Http\Controllers\Admin\OrderFileDownloadController;
use App\Http\Controllers\Admin\OrderExportController;
use App\Http\Controllers\Admin\TtnBatchController;
use App\Http\Controllers\Admin\TtnAddressController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

Route::get('/', function () {
    return view('home');
});

Route::middleware(['web', 'admin.basic'])->prefix('admin')->name('admin.')->group(function () {       
    // Orders
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
    Route::post(
        '/order-files/bulk-download',
        [OrderFileDownloadController::class, 'bulkDownload']
    )->name('order-files.bulk-download');

    // Product Dimensions
    Route::get(
    '/product-dimensions',
    [ProductDimensionController::class, 'index']
    )->name('product-dimensions.index');

    Route::post(
        '/product-dimensions',
        [ProductDimensionController::class, 'store']
    )->name('product-dimensions.store');

    Route::post(
        '/product-dimensions/bulk-update',
        [ProductDimensionController::class, 'bulkUpdate']
    )->name('product-dimensions.bulk-update');

    Route::post(
        '/product-dimensions/{productDimension}/delete',
        [ProductDimensionController::class, 'destroy']
    )->name('product-dimensions.destroy');

    // NP TTN Batch
    Route::post(
        '/ttn-batches',
        [TtnBatchController::class, 'store']
    )->name('ttn-batches.store');

    Route::get(
        '/ttn-batches',
        [TtnBatchController::class, 'index']
    )->name('ttn-batches.index');

    Route::get(
        '/ttn-batches/{batch}',
        [TtnBatchController::class, 'show']
    )->name('ttn-batches.show');

    Route::post(
        '/ttn-batches/{batch}/process-next',
        [TtnBatchController::class, 'processNext']
    )->name('ttn-batches.process-next');

    Route::post(
        '/ttn-batches/{batch}/retry-failed',
        [TtnBatchController::class, 'retryFailed']
    )->name('ttn-batches.retry-failed');

    Route::post(
        '/ttn-batches/{batch}/process-next',
        [TtnBatchController::class, 'processNext']
    )->name('ttn-batches.process-next');

    Route::post(
        '/orders/{order}/ttn/delete',
        [OrderAdminController::class, 'deleteTtn']
    )->name('orders.ttn.delete');

    Route::post(
        '/ttn-batches/{batch}/delete-next',
        [TtnBatchController::class, 'deleteNext']
    )->name('ttn-batches.delete-next');

    Route::post(
        '/ttn-batches/{batch}/retry-failed',
        [TtnBatchController::class, 'retryFailed']
    )->name('ttn-batches.retry-failed');

    Route::get(
        '/ttn-batches/{batch}/entries/{entry}/address',
        [TtnAddressController::class, 'show']
    )->name('ttn-batches.address.show');

    Route::post(
        '/ttn-batches/{batch}/entries/{entry}/address/settlement',
        [TtnAddressController::class, 'storeSettlement']
    )->name('ttn-batches.address.settlement');

    Route::post(
        '/ttn-batches/{batch}/entries/{entry}/address/street',
        [TtnAddressController::class, 'storeStreet']
    )->name('ttn-batches.address.street');

    Route::post(
        '/ttn-batches/{batch}/entries/{entry}/address/reset',
        [TtnAddressController::class, 'reset']
    )->name('ttn-batches.address.reset');

    // Export Excel
    Route::post(
        '/ttn-batches/{batch}/export',
        [OrderExportController::class, 'generate']
    )->name('ttn-batches.export');

    Route::get(
        '/order-exports/{export}/download',
        [OrderExportController::class, 'download']
    )->name('order-exports.download');

    Route::delete(
        '/ttn-batches/{batch}/order-exports/{export}',
        [OrderExportController::class, 'destroy']
    )->name('order-exports.destroy');

    Route::post(
        '/product-dimensions/import',
        [ProductDimensionController::class, 'importCsv']
    )->name('product-dimensions.import');
});

Route::get('/home', [App\Http\Controllers\HomeController::class, 'index'])->name('home');