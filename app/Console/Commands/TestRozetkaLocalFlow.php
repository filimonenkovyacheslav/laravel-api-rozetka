<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\ProductDimension;
use App\Services\OrderTtnService;
use App\Services\Rozetka\RozetkaFulfillmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TestRozetkaLocalFlow extends Command
{
    protected $signature =
        'rozetka:test-local-flow
        {--rz-code=493104624 : RZ-код товару для локального тесту}';

    protected $description =
        'Локальний тест нового Rozetka flow без зовнішніх API-запитів';

    public function handle(
        RozetkaFulfillmentService $fulfillmentService,
        OrderTtnService $ttnService
    ) {
        $rzCode = trim(
            (string) $this->option('rz-code')
        );

        $dimension = ProductDimension::query()
            ->with('places')
            ->where('rz_code', $rzCode)
            ->first();

        if (!$dimension) {
            $this->error(
                'Не знайдено габарити товару RZ ' .
                $rzCode
            );

            return 1;
        }

        $this->info(
            'Локальний тест Rozetka API flow'
        );

        $this->line(
            'RZ code: ' . $rzCode
        );

        $this->line(
            'ROZETKA_ACTIONS_ENABLED: ' .
            (
                config(
                    'services.rozetka.actions_enabled',
                    false
                )
                    ? 'true'
                    : 'false'
            )
        );

        DB::beginTransaction();

        try {
            /*
             * Создаём временный новый Rozetka-заказ.
             */
            $order = Order::create([
                'guid' => (string) Str::uuid(),

                'order_num' =>
                    'LOCAL-TEST-' . time(),

                'comment' =>
                    'Local Rozetka API test',

                'status' =>
                    'created',

                'rozetka_status' =>
                    'new',

                'delivery_type' =>
                    'DropShipping',

                'cash_on_delivery' =>
                    0,

                'delivery_company' =>
                    'Nova Poshta',

                'created_at_partner' =>
                    now(),

                'updated_at_partner' =>
                    now(),

                'rozetka_synced_at' =>
                    now(),
            ]);

            $item = $order->items()->create([
                'supplier_code' =>
                    'LOCAL-TEST-' . $rzCode,

                'rz_code' =>
                    $rzCode,

                'quantity' =>
                    1,

                'confirmed_quantity' =>
                    null,

                'price' =>
                    null,

                'reserved_quantity' =>
                    1,

                'serial_number' =>
                    json_encode([]),
            ]);

            $this->newLine();

            $this->info(
                '1. Визначення типу замовлення'
            );

            if (!$ttnService->usesRozetkaApi($order)) {
                throw new \RuntimeException(
                    'OrderTtnService помилково визначив ' .
                    'нове замовлення як legacy.'
                );
            }

            $this->line(
                'OK: використовується новий Rozetka API.'
            );

            /*
             * Проверяем payload подтверждения.
             */
            $this->newLine();

            $this->info(
                '2. Confirm payload'
            );

            $confirmPayload =
                $fulfillmentService
                    ->buildConfirmPayload($order);

            $this->line(
                json_encode(
                    $confirmPayload,
                    JSON_PRETTY_PRINT |
                    JSON_UNESCAPED_UNICODE |
                    JSON_UNESCAPED_SLASHES
                )
            );

            /*
             * Preview cargoSeats без подтверждения.
             */
            $this->newLine();

            $this->info(
                '3. Preview cargoSeats'
            );

            $previewSeats =
                $fulfillmentService
                    ->previewCargoSeats($order);

            $this->line(
                json_encode(
                    $previewSeats,
                    JSON_PRETTY_PRINT |
                    JSON_UNESCAPED_UNICODE |
                    JSON_UNESCAPED_SLASHES
                )
            );

            /*
             * Реальный buildCargoSeats должен отказать,
             * потому что confirmed_quantity = NULL.
             */
            $this->newLine();

            $this->info(
                '4. Перевірка захисту до підтвердження'
            );

            try {
                $fulfillmentService
                    ->buildCargoSeats($order);

                throw new \RuntimeException(
                    'Помилка тесту: cargoSeats сформовано ' .
                    'без confirmed_quantity.'
                );
            } catch (\RuntimeException $e) {
                if (
                    strpos(
                        $e->getMessage(),
                        'ще не підтверджено повністю'
                    ) === false
                ) {
                    throw $e;
                }

                $this->line(
                    'OK: створення ТТН заблоковано ' .
                    'до повного підтвердження.'
                );
            }

            /*
             * Имитируем успешный ответ /confirm.
             *
             * Никакого HTTP-запроса нет.
             */
            $this->newLine();

            $this->info(
                '5. Імітація confirmedQuantity = 1'
            );

            $item->update([
                'confirmed_quantity' => 1,
            ]);

            $order->update([
                'rozetka_status' =>
                    'confirmed',
            ]);

            $order->unsetRelation('items');

            /*
             * Теперь реальный cargoSeats должен собраться.
             */
            $cargoSeats =
                $fulfillmentService
                    ->buildCargoSeats($order);

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
                'Локальний тест успішно пройдено.'
            );

            $this->line(
                'Зовнішні API не викликались.'
            );

            $this->line(
                'Тестові записи будуть видалені rollback.'
            );

            return 0;
        } catch (\Throwable $e) {
            $this->error(
                $e->getMessage()
            );

            return 1;
        } finally {
            DB::rollBack();
        }
    }
}