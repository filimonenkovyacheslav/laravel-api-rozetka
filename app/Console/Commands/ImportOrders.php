<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Arr;

class ImportOrders extends Command
{
    protected $signature = 'orders:import';
    protected $description = 'Import or update orders from external API';

    public function handle()
    {
        /*$apiUrl   = rtrim(config('services.partner.api_url'), '/');
        $jwtToken = config('services.partner.jwt_token');

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $jwtToken,
            'Accept'        => 'application/json',
        ])->get($apiUrl . '/api/orders/list');

        if (!$response->successful()) {
            $this->error('❌ Failed to fetch orders. HTTP: ' . $response->status());
            return Command::FAILURE;
        }

        $orders = $response->json('orders') ?? $response->json();

        if (!is_array($orders)) {
            $this->error('❌ Invalid response format');
            return Command::FAILURE;
        }

        $imported = 0;

        foreach ($orders as $data) {
            DB::beginTransaction();

            try {
                $guid = $data['partnerOrderId'] ?? null;

                if (!$guid) {
                    DB::rollBack();
                    continue;
                }

                $order = Order::updateOrCreate(
                    ['guid' => $guid],
                    [
                        'comment'              => $data['comment'] ?? null,
                        'status'               => $data['status'] ?? 'pending',
                        'delivery_type'        => $data['deliveryAddressType'] ?? null,
                        'cash_on_delivery'     => $data['cashOnDelivery'] ?? null,
                        'delivery_company'     => $data['deliveryCompanyName'] ?? null,
                        'delivery_address_id'  => $data['deliveryAddressId'] ?? null,
                        'delivery_city'        => $data['deliveryCity'] ?? null,
                        'delivery_street'      => $data['deliveryStreet'] ?? null,
                        'delivery_phone'       => $data['deliveryPhone'] ?? null,
                        'customer_name'        => $data['customerName'] ?? null,
                        'tracking_number'      => $data['tracking_number'] ?? null,
                        'created_at_partner'   => $data['createdAt'] ?? null,
                        'updated_at_partner'   => $data['updatedAt'] ?? null,
                    ]
                );

                $order->items()->delete();

                foreach ($data['products'] ?? [] as $item) {
                    $order->items()->create([
                        'supplier_code'     => $item['supplier_code'],
                        'rz_code'           => $item['RZ_code'],
                        'quantity'          => $item['quantity'],
                        'price'             => $item['price'],
                        'reserved_quantity' => $item['reservedQuantity'] ?? null,
                        'serial_number'     => !empty($item['SerialNumber'])
                            ? json_encode($item['SerialNumber'])
                            : null,
                    ]);
                }

                DB::commit();
                $imported++;

            } catch (\Throwable $e) {
                DB::rollBack();
                $this->error("❌ Order {$guid} failed: " . $e->getMessage());
            }
        }

        $this->info("✅ Imported / updated orders: {$imported}");*/

        return Command::SUCCESS;
    }
}
