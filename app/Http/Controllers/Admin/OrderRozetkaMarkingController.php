<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Rozetka\RozetkaMarkingService;
use Illuminate\Http\Request;
use Throwable;

class OrderRozetkaMarkingController extends Controller
{
    /**
     * Download a Nova Poshta marking for one existing Rozetka order.
     * This does not confirm an order or create/replace its TTN.
     */
    public function download(
        Request $request,
        Order $order,
        RozetkaMarkingService $markingService
    ) {
        $validated = $request->validate([
            'size' => ['nullable', 'in:85x85,100x100'],
        ]);

        $size = $validated['size'] ?? '100x100';

        try {
            $result = $markingService->printOrderPdf(
                $order,
                $size
            );

            return response(
                $result['body'],
                200,
                [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' =>
                        'attachment; filename="rozetka-np-marking-order-' .
                        $order->id . '-' . $size . '.pdf"',
                    'X-Markings-Printed' => (string) $result['printed'],
                    'X-Markings-Skipped' => (string) $result['skipped'],
                    'Cache-Control' => 'private, no-store, no-cache, must-revalidate',
                ]
            );
        } catch (Throwable $e) {
            report($e);

            return redirect()
                ->route('admin.orders.show', $order)
                ->with(
                    'err',
                    'Не вдалося завантажити маркування НП: ' .
                    $e->getMessage()
                );
        }
    }
}
