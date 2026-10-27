<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\Rozetka\RozetkaFulfillmentService;
use Illuminate\Console\Command;

class PreviewRozetkaOrder extends Command
{
    protected $signature =
        'rozetka:preview-order
        {order : Local ID або GUID замовлення}';

    protected $description =
        'Попередній перегляд payload для нового Rozetka API без відправки';

    public function handle(
        RozetkaFulfillmentService $service
    ) {
        $value = trim(
            (string) $this->argument('order')
        );

        $query = Order::query()
            ->with('items');

        if (ctype_digit($value)) {
            $order = $query
                ->whereKey((int) $value)
                ->first();
        } else {
            $order = $query
                ->where('guid', $value)
                ->first();
        }

        if (!$order) {
            $this->error(
                'Замовлення не знайдено.'
            );

            return 1;
        }

        $this->info(
            'Замовлення #' . $order->id
        );

        $this->line(
            'GUID: ' . $order->guid
        );

        $this->line(
            'Rozetka status: ' .
            ($order->rozetka_status ?: '—')
        );

        $this->newLine();

        try {
            $confirmPayload =
                $service->buildConfirmPayload(
                    $order
                );

            $this->info(
                'Confirm payload:'
            );

            $this->line(
                json_encode(
                    $confirmPayload,
                    JSON_PRETTY_PRINT |
                    JSON_UNESCAPED_UNICODE |
                    JSON_UNESCAPED_SLASHES
                )
            );
        } catch (\Throwable $e) {
            $this->error(
                'Confirm payload: ' .
                $e->getMessage()
            );
        }

        $this->newLine();

        try {
            $cargoSeats =
                $service->previewCargoSeats(
                    $order
                );

            $this->info(
                'Cargo seats:'
            );

            $this->line(
                json_encode(
                    $cargoSeats,
                    JSON_PRETTY_PRINT |
                    JSON_UNESCAPED_UNICODE |
                    JSON_UNESCAPED_SLASHES
                )
            );

            $this->newLine();

            $this->info(
                'Кількість місць: ' .
                count($cargoSeats)
            );
        } catch (\Throwable $e) {
            $this->warn(
                'Cargo seats поки неможливо сформувати: ' .
                $e->getMessage()
            );
        }

        return 0;
    }
}