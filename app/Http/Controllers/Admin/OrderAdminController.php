<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class OrderAdminController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'q' => ['nullable','string','max:200'],
            'status' => ['nullable', Rule::in(['pending','created','updated','shipped','canceled'])],
            'from' => ['nullable','date_format:Y-m-d'],
            'to' => ['nullable','date_format:Y-m-d'],
            'per_page' => ['nullable','integer','min:5','max:100'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 20);
        $query = Order::query()->withExists('files')->orderByDesc('id');

        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (!empty($validated['q'])) {
            $q = $validated['q'];
            $query->where(function($sub) use ($q) {
                $sub->where('guid', 'like', "%{$q}%")
                    ->orWhere('tracking_number', 'like', "%{$q}%")
                    ->orWhere('delivery_phone', 'like', "%{$q}%")
                    ->orWhere('customer_name', 'like', "%{$q}%");
            });
        }

        if (!empty($validated['from']) || !empty($validated['to'])) {
            $from = !empty($validated['from']) ? $validated['from'].' 00:00:00' : '1970-01-01 00:00:00';
            $to   = !empty($validated['to'])   ? $validated['to'].' 23:59:59'   : now()->format('Y-m-d H:i:s');
            $query->whereBetween('created_at', [$from, $to]);
        }

        $orders = $query->paginate($perPage)->appends($request->query());

        return view('admin.orders.index', [
            'orders' => $orders,
            'filters' => [
                'q' => $validated['q'] ?? '',
                'status' => $validated['status'] ?? '',
                'from' => $validated['from'] ?? '',
                'to' => $validated['to'] ?? '',
                'per_page' => $perPage,
            ],
        ]);
    }

    public function show(Order $order)
    {
        $order->load(['items', 'files']);
        return view('admin.orders.show', compact('order'));
    }

    public function edit(Order $order)
    {
        return view('admin.orders.edit', compact('order'));
    }

    public function update(Request $request, Order $order)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['pending','created','updated','shipped','canceled'])],

            'delivery_type' => ['nullable','string','max:255'],
            'delivery_address_id' => ['nullable','string','max:255'],
            'delivery_company' => ['nullable','string','max:255'],
            'delivery_city' => ['nullable','string','max:255'],
            'delivery_street' => ['nullable','string','max:255'],
            'delivery_phone' => ['nullable','string','max:255'],
            'customer_name' => ['nullable','string','max:255'],
        ]);

        $order->update([
            'status' => $data['status'],

            'delivery_type' => $data['delivery_type'] ?? null,
            'delivery_address_id' => $data['delivery_address_id'] ?? null,
            'delivery_company' => $data['delivery_company'] ?? null,
            'delivery_city' => $data['delivery_city'] ?? null,
            'delivery_street' => $data['delivery_street'] ?? null,
            'delivery_phone' => $data['delivery_phone'] ?? null,
            'customer_name' => $data['customer_name'] ?? null,
        ]);

        return redirect()->route('admin.orders.show', $order)->with('ok', 'Order updated');
    }

    public function cancel(Order $order)
    {
        if ($order->status !== 'canceled') {
            $order->update(['status' => 'canceled']);
        }
        return redirect()->route('admin.orders.show', $order)->with('ok', 'Order canceled');
    }

    public function ship(Request $request, Order $order)
    {
        if ($order->status === 'canceled') {
            return redirect()->route('admin.orders.show', $order)->with('err', 'Canceled order cannot be shipped');
        }

        $data = $request->validate([
            'tracking_number' => ['required','string','max:255'],
        ]);

        $order->update([
            'tracking_number' => $data['tracking_number'],
            'status' => 'shipped',
        ]);

        return redirect()->route('admin.orders.show', $order)->with('ok', 'Order marked as shipped');
    }

    public function editItem(OrderItem $item)
    {
        return view('admin.order_items.edit', compact('item'));
    }

    public function updateItem(Request $request, OrderItem $item)
    {
        $data = $request->validate([
            'reserved_quantity' => ['required','integer','min:0'],
        ]);

        $item->update([
            'reserved_quantity' => $data['reserved_quantity'],
        ]);

        return redirect()->route('admin.orders.show', $item->order_id)
            ->with('ok', 'Reserved quantity updated');
    }

    public function downloadFile(OrderFile $file)
    {
        if (!Storage::disk('local')->exists($file->path)) {
            abort(404, 'File not found');
        }
        return Storage::disk('local')->download($file->path);
    }

    public function deleteFile(OrderFile $file)
    {
        if (Storage::disk('local')->exists($file->path)) {
            Storage::disk('local')->delete($file->path);
        }
        $file->delete();

        return back()->with('ok', 'File deleted');
    }
}
