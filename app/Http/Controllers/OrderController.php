<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Database\QueryException;

class OrderController extends Controller
{
    // GET /api/orders/list?page=1&per_page=20
    public function list(Request $request)
    {
        $perPage = min((int) $request->query('per_page', 20), 100);
        $page    = max((int) $request->query('page', 1), 1);
        $orders = [];
        $total = 0;

        $orders = Order::with('items')->orderBy('id', 'desc')->get()->toArray();
        $total = count($orders);        
        $totalPages = (int) ceil($total / max($perPage, 1));

        return response()->json([
            'orders'     => $orders,
            'total'      => $total,
            'page'       => $page,
            'perPage'    => $perPage,
            'totalPages' => $totalPages,
        ]);
    }

    
    // GET /api/orders?from=Y-m-d&to=Y-m-d&status=pending&page=1&per_page=20
    public function index(Request $request)
    {
        $orders = [];
        $total = 0;
        
        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', Rule::in(['pending','created','updated','shipped','canceled'])],
            'page' => ['nullable','integer','min:1'],
            'per_page' => ['nullable','integer','min:1','max:100'],
        ]);

        $perPage = (int) $request->query('per_page', 20);
        $page    = (int) $request->query('page', 1);
        $status = $request->query('status');
        $from = $request->query('from');
        $to = $request->query('to');

        $orders = Order::with('items')
        ->whereBetween('created_at', [$from, $to])
        ->orderBy('id', 'desc')->get()->toArray();
        $total = count($orders);        
        $totalPages = (int) ceil($total / max($perPage, 1));

        return response()->json([
            'orders'     => $orders,
            'total'      => $total,
            'page'       => $page,
            'perPage'    => $perPage,
            'totalPages' => $totalPages,
        ]);
    }

    
    // GET /api/order/{guid}
    public function show(string $guid)
    {
        $order = Order::with('items')->where('partnerOrderId', $guid)->first();

        if (!$order) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        return response()->json($order);
    }

    
    // POST /api/order/create
    public function create(Request $request)
    {
        $validator = Validator::make($request->all()['header'], [
            'partnerOrderId' => ['required','string'],
            'comment' => ['nullable','string'],
            'deliveryAddresType' => ['nullable','string'],
            'cashOnDelivery' => ['nullable','numeric'],
            'deliveryCompanyName' => ['nullable','string'],
            'deliveryAddressId' => ['nullable','string'],
            'CustomerName' => ['nullable','string'],
            'deliveryCity' => ['nullable','string'],
            'deliveryPhone' => ['nullable','string'],
            'deliveryStreet' => ['nullable','string'],
            'createdAt' => ['nullable','string'],
            'updatedAt' => ['nullable','string'],
            'tracking_number' => ['nullable','string'],
            'products' => ['required','array','min:1'],
            'products.*.supplier_code' => ['required','string'],
            'products.*.RZ_code' => ['required'],
            'products.*.quantity' => ['required','integer','min:1'],
            'products.*.price' => ['required','numeric','min:0'],
            'products.*.reservedQuantity' => ['nullable','integer'],
            'products.*.SerialNumber' => ['nullable','array'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Bad Request',
                'message' => 'Недійсні дані замовлення',
                'details' => $validator->errors(),
            ], 400);
        }

        $data = $validator->validated();
        $guid = $data['partnerOrderId'];

        if (Order::where('guid', $guid)->exists()) {
            return response()->json([
                'error' => 'Bad Request',
                'message' => 'Замовлення вже існує',
            ], 400);
        }

        try {
            $order = Order::create([
                'guid' => $guid,
                'comment' => $data['comment'] ?? null,
                'status' => 'created',
                'delivery_type' => $data['deliveryAddresType'] ?? null,
                'cash_on_delivery' => $data['cashOnDelivery'] ?? null,
                'delivery_company' => $data['deliveryCompanyName'] ?? null,
                'delivery_address_id' => $data['deliveryAddressId'] ?? null,
                'delivery_city' => $data['deliveryCity'] ?? null,
                'delivery_street' => $data['deliveryStreet'] ?? null,
                'delivery_phone' => $data['deliveryPhone'] ?? null,
                'customer_name' => $data['CustomerName'] ?? null,
                'tracking_number' => $data['tracking_number'] ?? null,
                'created_at_partner' => $data['createdAt'] ?? null,
                'updated_at_partner' => $data['updatedAt'] ?? null,
            ]);

            foreach ($data['products'] as $item) {
                $order->items()->create([
                    'supplier_code' => $item['supplier_code'],
                    'rz_code'       => $item['RZ_code'],
                    'quantity'      => $item['quantity'],
                    'price'         => $item['price'],
                    'reserved_quantity' => $item['reservedQuantity'] ?? $item['quantity'],
                    'serial_number' => isset($item['SerialNumber']) ? json_encode($item['SerialNumber']) : null,
                ]);
            }

            return response()->json([
                'guid' => $guid,
                'status' => 'pending',
            ], 250);
        } catch (QueryException $e) {
            if ($e->getCode() == 23000) {
                return response()->json([
                    'error' => 'Bad Request',
                    'message' => 'Недійсні дані замовлення',
                ], 400);
            }
            throw $e;
        }       
    }

    
    // PUT /api/order/edit/{guid}
    public function edit(Request $request, string $guid)
    {
        $order = Order::where('guid', $guid)->first();

        if (!$order) {
            return response()->json([
                'error' => 'Not Found',
                'message' => 'Замовлення не знайдено',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'deliveryAddresType' => ['nullable','string'],
            'cashOnDelivery' => ['nullable','numeric'],
            'deliveryAddressId' => ['nullable','string'],
            'deliveryCompanyName' => ['nullable','string'],
            'CustomerName' => ['nullable','string'],
            'deliveryCity' => ['nullable','string'],
            'deliveryPhone' => ['nullable','string'],
            'deliveryStreet' => ['nullable','string'],
            'createdAt' => ['nullable','string'],
            'updatedAt' => ['nullable','string'],
            'tracking_number' => ['nullable','string'],
            'products' => ['required','array','min:1'],
            'products.*.supplier_code' => ['required','string'],
            'products.*.RZ_code' => ['required'],
            'products.*.quantity' => ['required','integer','min:1'],
            'products.*.price' => ['required','numeric','min:0'],
            'products.*.reservedQuantity' => ['nullable','integer'],
            'products.*.SerialNumber' => ['nullable','array'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'error' => 'Bad Request',
                'message' => 'Недійсні дані замовлення',
                'details' => $validator->errors(),
            ], 400);
        }

        $data = $validator->validated();
        $order->update([
            'delivery_type'      => $data['deliveryAddresType'] ?? $order->delivery_type,
            'cash_on_delivery'   => $data['cashOnDelivery'] ?? $order->cash_on_delivery,
            'delivery_company'   => $data['deliveryCompanyName'] ?? $order->delivery_company,
            'delivery_address_id'=> $data['deliveryAddressId'] ?? $order->delivery_address_id,
            'delivery_city'      => $data['deliveryCity'] ?? $order->delivery_city,
            'delivery_street'    => $data['deliveryStreet'] ?? $order->delivery_street,
            'delivery_phone'     => $data['deliveryPhone'] ?? $order->delivery_phone,
            'customer_name'      => $data['CustomerName'] ?? $order->customer_name,
            'tracking_number' => $data['tracking_number'] ?? $order->tracking_number,
            'created_at_partner' => $data['createdAt'] ?? $order->created_at_partner,
            'updated_at_partner' => $data['updatedAt'] ?? $order->updated_at_partner,
            'status'             => isset($data['tracking_number']) ? 'shipped' : 'updated',
        ]);

        $order->items()->delete();
        foreach ($data['products'] as $item) {
            $order->items()->create([
                'supplier_code' => $item['supplier_code'],
                'rz_code'       => $item['RZ_code'],
                'quantity'      => $item['quantity'],
                'price'         => $item['price'],
                'reserved_quantity' => $item['reservedQuantity'] ?? $item['quantity'],
                'serial_number' => isset($item['SerialNumber']) ? json_encode($item['SerialNumber']) : null,
            ]);
        }

        return response()->json([
            'guid' => $order->guid,
            'status' => $order->status,
            'tracking_number' => $order->tracking_number,
            'products' => $order->items->map(function ($item, $index) {
                return [
                    'supplier_code' => $item->supplier_code,
                    'RZ_code'       => (int) $item->rz_code,
                    'quantity'      => (int) $item->quantity,
                    'reservedQuantity' => (int) $item->reserved_quantity,
                    'serialNumber'  => $item->serial_number ? $item->serial_number : [], 
                ];
            }),
        ], 200);
    }


    // DELETE /api/order/cancel/{guid}
    public function cancel(string $guid)
    {
        $order = Order::where('guid', $guid)->first();

        if (!$order) {
            return response()->json([
                'error' => 'Not Found',
                'message' => 'Замовлення не знайдено',
            ], 404);
        }

        $order->update([
            'status' => 'canceled',
        ]);

        return response()->json([
            'partnerOrderId' => $guid,
            'status' => 'canceled',
        ]);
    }


    // GET /api/order/status/{guid}
    public function status(string $guid)
    {
        $order = Order::with('items')->where('guid', $guid)->first();

        if (!$order) {
            return response()->json([
                'error' => 'Not Found',
                'message' => 'Замовлення не знайдено',
            ], 404);
        }

        /*return response()->json([
            'guid' => $order->guid,
            'status' => $order->status,
            'tracking_number' => $order->tracking_number,
            'products' => $order->items->map(function ($item) {
                return [
                    'supplier_code' => $item->supplier_code,
                    'RZ_code'       => $item->rz_code,
                    'quantity'      => $item->quantity,
                    'reservedQuantity' => $item->reserved_quantity,
                    'SerialNumber'  => $item->serial_number, 
                ];
            }),
        ], 250);*/
        return response()->json([
            'guid' => $order->guid,
            'status' => $order->status,
            'tracking_number' => $order->tracking_number,
            'products' => $order->items->map(function ($item, $index) {
                return [
                    'supplier_code' => $item->supplier_code,
                    'RZ_code'       => (int) $item->rz_code,
                    'quantity'      => (int) $item->quantity,
                    'reservedQuantity' => (int) $item->reserved_quantity,
                    'serialNumber'  => $item->serial_number ? $item->serial_number : [], 
                ];
            }),
        ], 200);
    }


    // POST /api/order/{guid}/upload  (binary)
    public function upload(Request $request, string $guid)
    {
        $order = Order::where('guid', $guid)->first();

        if (!$order) {
            return response()->json([
                'error' => 'Not Found',
                'message' => 'Замовлення не знайдено',
            ], 404);
        }

        if ($request->getContent()) {
            $content = $request->getContent();

            if (empty($content)) {
                return response()->json(['error' => 'No file'], 400);
            }

            $fileGuid = (string) Str::uuid();
            $path = "orders/{$guid}/{$fileGuid}.pdf";

            Storage::disk('local')->put($path, $content);

            OrderFile::create([
                'guid' => $guid,
                'path' => $path,
            ]);

            return response()->json([
                'success' => true,
                'file_guid' => $fileGuid,
                'message' => 'Файл pdf завантажено',
            ]);
        }
        else {
            if (!$request->files->count()) {
                return response()->json([
                    'error' => 'Bad Request',
                    'message' => 'Файл не передано'
                ], 400);
            }

            /** @var \Illuminate\Http\UploadedFile $file */
            $file = collect($request->files->all())->first();

            if (!$file->isValid()) {
                return response()->json([
                    'error' => 'Bad Request',
                    'message' => 'Невірний файл'
                ], 400);
            }

            if ($file->getSize() > 20 * 1024 * 1024) {
                return response()->json([
                    'error' => 'Bad Request',
                    'message' => 'Файл завеликий'
                ], 400);
            }

            $fileGuid = (string) Str::uuid();
            $path = "orders/{$guid}/{$fileGuid}_" . $file->getClientOriginalName();

            Storage::disk('local')->put($path, file_get_contents($file->getRealPath()));

            OrderFile::create([
                'guid' => $guid,
                'path' => $path,
            ]);

            return response()->json([
                'success' => true,
                'file_guid' => $fileGuid,
                'message' => 'Файл успішно завантажено',
            ]);
        }    
    }

    // DELETE /api/file/{file_guid}/delete
    public function deleteFile(string $file_guid)
    {
        $file = OrderFile::where('path', 'like', "%{$file_guid}%")->first();

        if (!$file) {
            return response()->json([
                'error' => 'Not Found',
                'message' => 'Файл не знайдено',
            ], 404);
        }

        if (Storage::disk('local')->exists($file->path)) {
            Storage::disk('local')->delete($file->path);
        }

        $file->delete();

        return response()->json([
            'success' => true,
            'message' => 'Файл успішно видалено',
        ]);
    }
}


