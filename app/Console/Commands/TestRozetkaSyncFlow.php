<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\Rozetka\RozetkaOrderService;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class TestRozetkaSyncFlow extends Command
{
    protected $signature =
        'rozetka:test-sync-flow';

    protected $description =
        'Локальний тест оптимізованої синхронізації Rozetka через Http::fake';

    public function handle()
    {
        /*
         * Сохраняем текущую runtime-конфигурацию.
         * .env этот тест не меняет.
         */
        $oldApiUrl = config(
            'services.rozetka.api_url'
        );

        $oldToken = config(
            'services.rozetka.jwt_token'
        );

        $oldSyncEnabled = config(
            'services.rozetka.sync_enabled'
        );

        /*
         * Включаем sync только внутри
         * текущего artisan-процесса.
         */
        config([
            'services.rozetka.api_url' =>
                'https://fake-rozetka-sync.test',

            'services.rozetka.jwt_token' =>
                'fake-sync-token',

            'services.rozetka.sync_enabled' =>
                true,

            'services.rozetka.timeout' =>
                5,
        ]);

        DB::beginTransaction();

        try {
            $this->info(
                'Тест оптимізованої синхронізації Rozetka'
            );

            /*
             * =====================================================
             * 1. НЕИЗМЕНЁННЫЙ заказ
             * =====================================================
             *
             * status и updatedAt полностью совпадают.
             * Detail GET выполняться не должен.
             */
            $unchangedGuid =
                (string) Str::uuid();

            $unchanged = $this->createExistingOrder(
                $unchangedGuid,
                'confirmed',
                '2026-10-10 10:00:00',
                'UNCHANGED'
            );

            /*
             * Фиксируем synced_at, чтобы потом убедиться,
             * что skipped-заказ вообще не трогали.
             */
            $unchangedSyncedAt =
                '2026-10-12 08:00:00';

            $unchanged->forceFill([
                'rozetka_synced_at' =>
                    $unchangedSyncedAt,
            ])->save();

            /*
             * =====================================================
             * 2. ИЗМЕНЁННЫЙ заказ
             * =====================================================
             *
             * Локально updatedAt старый.
             * В summary будет более новый updatedAt.
             */
            $changedGuid =
                (string) Str::uuid();

            $changed = $this->createExistingOrder(
                $changedGuid,
                'confirmed',
                '2026-10-10 11:00:00',
                'CHANGED'
            );

            /*
             * =====================================================
             * 3. НОВЫЙ заказ
             * =====================================================
             *
             * В локальной БД его вообще нет.
             */
            $newGuid =
                (string) Str::uuid();

            /*
             * Счётчики HTTP-вызовов.
             */
            $calls = [];

            Http::swap(
                new HttpFactory()
            );

            Http::fake(function (
                HttpRequest $request
            ) use (
                $unchangedGuid,
                $changedGuid,
                $newGuid,
                &$calls
            ) {
                $path = (string) parse_url(
                    $request->url(),
                    PHP_URL_PATH
                );

                $calls[] = [
                    'method' =>
                        $request->method(),

                    'path' =>
                        $path,

                    'data' =>
                        $request->data(),
                ];

                /*
                 * Список заказов.
                 */
                if (
                    $request->method() === 'GET' &&
                    $path === '/dropshipping/orders'
                ) {
                    return Http::response([
                        'totalCount' => 3,
                        'currentPage' => 1,

                        'orders' => [
                            /*
                             * Полностью совпадает
                             * с локальным состоянием.
                             */
                            [
                                'orderId' =>
                                    $unchangedGuid,

                                'createdAt' =>
                                    '2026-10-09 10:00:00',

                                'updatedAt' =>
                                    '2026-10-10 10:00:00',

                                'orderStatus' =>
                                    'confirmed',

                                'itemsCount' =>
                                    1,
                            ],

                            /*
                             * updatedAt изменился.
                             */
                            [
                                'orderId' =>
                                    $changedGuid,

                                'createdAt' =>
                                    '2026-10-09 11:00:00',

                                'updatedAt' =>
                                    '2026-10-12 12:30:00',

                                'orderStatus' =>
                                    'updated',

                                'itemsCount' =>
                                    1,
                            ],

                            /*
                             * Нового заказа локально нет.
                             */
                            [
                                'orderId' =>
                                    $newGuid,

                                'createdAt' =>
                                    '2026-10-12 13:00:00',

                                'updatedAt' =>
                                    null,

                                'orderStatus' =>
                                    'new',

                                'itemsCount' =>
                                    1,
                            ],
                        ],
                    ], 200);
                }

                /*
                 * Detail изменённого заказа.
                 */
                if (
                    $request->method() === 'GET' &&
                    $path ===
                        '/dropshipping/orders/' .
                        $changedGuid
                ) {
                    return Http::response(
                        $this->makeOrderDetails(
                            $changedGuid,
                            'РОЗ-LOCAL-CHANGED',
                            'updated',
                            '2026-10-09 11:00:00',
                            '2026-10-12 12:30:00',
                            'LOCAL-SYNC-CHANGED',
                            493104624
                        ),
                        200
                    );
                }

                /*
                 * Detail нового заказа.
                 */
                if (
                    $request->method() === 'GET' &&
                    $path ===
                        '/dropshipping/orders/' .
                        $newGuid
                ) {
                    return Http::response(
                        $this->makeOrderDetails(
                            $newGuid,
                            'РОЗ-LOCAL-NEW',
                            'new',
                            '2026-10-12 13:00:00',
                            null,
                            'LOCAL-SYNC-NEW',
                            493104624
                        ),
                        200
                    );
                }

                /*
                 * Очень важная проверка:
                 * неизменённый заказ сюда попасть не должен.
                 */
                if (
                    $request->method() === 'GET' &&
                    $path ===
                        '/dropshipping/orders/' .
                        $unchangedGuid
                ) {
                    throw new RuntimeException(
                        'TEST FAILED: виконано detail GET ' .
                        'для незміненого замовлення.'
                    );
                }

                throw new RuntimeException(
                    'TEST FAILED: несподіваний HTTP-запит: ' .
                    $request->method() .
                    ' ' .
                    $path
                );
            });

            $service =
                app(RozetkaOrderService::class);

            $result = $service->sync([
                'dateFrom' =>
                    '2026-09-12 00:00:00',

                'dateTo' =>
                    '2026-10-12 23:59:59',
            ]);

            /*
             * =====================================================
             * Проверяем итоговые счётчики.
             * =====================================================
             */
            $this->assertSame(
                3,
                $result['total'],
                'total'
            );

            $this->assertSame(
                2,
                $result['processed'],
                'processed'
            );

            $this->assertSame(
                1,
                $result['created'],
                'created'
            );

            $this->assertSame(
                1,
                $result['updated'],
                'updated'
            );

            $this->assertSame(
                1,
                $result['skipped'],
                'skipped'
            );

            $this->assertSame(
                0,
                $result['failed'],
                'failed'
            );

            /*
             * =====================================================
             * Проверяем количество HTTP-запросов.
             * =====================================================
             */
            $this->assertSame(
                1,
                $this->countCalls(
                    $calls,
                    'GET',
                    '/dropshipping/orders'
                ),
                'GET list'
            );

            $this->assertSame(
                0,
                $this->countCalls(
                    $calls,
                    'GET',
                    '/dropshipping/orders/' .
                        $unchangedGuid
                ),
                'detail GET unchanged'
            );

            $this->assertSame(
                1,
                $this->countCalls(
                    $calls,
                    'GET',
                    '/dropshipping/orders/' .
                        $changedGuid
                ),
                'detail GET changed'
            );

            $this->assertSame(
                1,
                $this->countCalls(
                    $calls,
                    'GET',
                    '/dropshipping/orders/' .
                        $newGuid
                ),
                'detail GET new'
            );

            /*
             * =====================================================
             * Проверяем изменённый заказ.
             * =====================================================
             */
            $changed->refresh();

            $this->assertTrue(
                $changed->rozetka_status ===
                    'updated',
                'Змінений заказ не отримав статус updated.'
            );

            $this->assertTrue(
                $changed->status ===
                    'updated',
                'Не оновлено legacy status зміненого замовлення.'
            );

            $this->assertTrue(
                $changed->updated_at_partner !== null &&
                \Carbon\Carbon::parse(
                    $changed->updated_at_partner
                )->format('Y-m-d H:i:s') ===
                    '2026-10-12 12:30:00',
                'Не оновлено updated_at_partner.'
            );

            /*
             * =====================================================
             * Проверяем новый заказ.
             * =====================================================
             */
            $newOrder = Order::query()
                ->with('items')
                ->where(
                    'guid',
                    $newGuid
                )
                ->first();

            $this->assertTrue(
                $newOrder !== null,
                'Новий заказ не створено.'
            );

            $this->assertTrue(
                $newOrder->rozetka_status ===
                    'new',
                'Новий заказ має неправильний rozetka_status.'
            );

            $this->assertTrue(
                $newOrder->order_num ===
                    'РОЗ-LOCAL-NEW',
                'Новий заказ має неправильний order_num.'
            );

            $this->assertSame(
                1,
                $newOrder->items->count(),
                'Кількість позицій нового замовлення'
            );

            $this->assertTrue(
                $newOrder->items
                    ->first()
                    ->supplier_code ===
                    'LOCAL-SYNC-NEW',
                'Неправильний supplierCode нового товару.'
            );

            /*
             * =====================================================
             * Проверяем неизменённый заказ.
             * =====================================================
             */
            $unchanged->refresh();

            $this->assertTrue(
                $unchanged->rozetka_status ===
                    'confirmed',
                'Незмінений заказ був модифікований.'
            );

            $this->assertTrue(
                $unchanged->rozetka_synced_at
                    ->format('Y-m-d H:i:s') ===
                    $unchangedSyncedAt,
                'У skipped-заказа змінився rozetka_synced_at.'
            );

            $this->newLine();

            $this->info(
                'Результат sync:'
            );

            $this->table(
                [
                    'Показник',
                    'Кількість',
                ],
                [
                    [
                        'Всього',
                        $result['total'],
                    ],
                    [
                        'Оброблено',
                        $result['processed'],
                    ],
                    [
                        'Створено',
                        $result['created'],
                    ],
                    [
                        'Оновлено',
                        $result['updated'],
                    ],
                    [
                        'Пропущено без detail GET',
                        $result['skipped'],
                    ],
                    [
                        'Помилок',
                        $result['failed'],
                    ],
                ]
            );

            $this->newLine();

            $this->info(
                'HTTP-перевірка:'
            );

            $this->line(
                'OK: новий заказ → detail GET.'
            );

            $this->line(
                'OK: змінений заказ → detail GET.'
            );

            $this->line(
                'OK: незмінений заказ → detail GET відсутній.'
            );

            $this->newLine();

            $this->info(
                'Тест оптимізованої синхронізації успішно пройдено.'
            );

            $this->line(
                'Жодного реального HTTP-запиту не виконано.'
            );

            $this->line(
                'Усі тестові зміни будуть видалені rollback.'
            );

            return 0;
        } catch (Throwable $e) {
            $this->error(
                get_class($e) .
                ': ' .
                $e->getMessage()
            );

            return 1;
        } finally {
            DB::rollBack();

            /*
             * Возвращаем runtime-конфигурацию.
             */
            config([
                'services.rozetka.api_url' =>
                    $oldApiUrl,

                'services.rozetka.jwt_token' =>
                    $oldToken,

                'services.rozetka.sync_enabled' =>
                    $oldSyncEnabled,
            ]);
        }
    }

    /**
     * Создать локальный существующий заказ,
     * который уже синхронизировался новым API.
     *
     * @param string $guid
     * @param string $rozetkaStatus
     * @param string $updatedAt
     * @param string $suffix
     * @return Order
     */
    private function createExistingOrder(
        $guid,
        $rozetkaStatus,
        $updatedAt,
        $suffix
    ) {
        $order = Order::create([
            'guid' =>
                $guid,

            'order_num' =>
                'РОЗ-LOCAL-' .
                $suffix,

            'comment' =>
                'РОЗ-LOCAL-' .
                $suffix,

            'status' =>
                'created',

            'rozetka_status' =>
                $rozetkaStatus,

            'delivery_type' =>
                'DropShipping',

            'cash_on_delivery' =>
                0,

            'delivery_company' =>
                'Nova Poshta',

            'sn_required' =>
                false,

            'created_at_partner' =>
                '2026-10-09 10:00:00',

            'updated_at_partner' =>
                $updatedAt,

            'rozetka_synced_at' =>
                '2026-10-12 08:00:00',
        ]);

        $order->items()->create([
            'supplier_code' =>
                'LOCAL-SYNC-' .
                $suffix,

            'rz_code' =>
                '493104624',

            'quantity' =>
                1,

            'confirmed_quantity' =>
                1,

            'price' =>
                null,

            'reserved_quantity' =>
                1,

            'serial_number' =>
                null,
        ]);

        return $order->fresh([
            'items',
        ]);
    }

    /**
     * Fake full-detail response.
     *
     * @param string $guid
     * @param string $orderNum
     * @param string $status
     * @param string $createdAt
     * @param string|null $updatedAt
     * @param string $supplierCode
     * @param int $rzCode
     * @return array
     */
    private function makeOrderDetails(
        $guid,
        $orderNum,
        $status,
        $createdAt,
        $updatedAt,
        $supplierCode,
        $rzCode
    ) {
        return [
            'orderId' =>
                $guid,

            'orderStatus' =>
                $status,

            'createdAt' =>
                $createdAt,

            'updatedAt' =>
                $updatedAt,

            'orderNum' =>
                $orderNum,

            'deliveryAddressType' =>
                'DropShipping',

            'cashOnDelivery' =>
                0,

            'deliveryCompanyName' =>
                'Nova Poshta',

            'deliveryAddressId' =>
                null,

            'customerName' =>
                'Local Sync Test',

            'deliveryCity' =>
                'Kyiv',

            'deliveryStreet' =>
                null,

            'deliveryPhone' =>
                '+380000000000',

            'ttn' =>
                null,

            'trackingStatusCode' =>
                null,

            'trackingStatus' =>
                null,

            'returnTtn' =>
                null,

            'documentLink' =>
                null,

            'incomingInvoiceNumber' =>
                null,

            'snRequired' =>
                0,

            'products' => [
                [
                    'supplierCode' =>
                        $supplierCode,

                    'rzCode' =>
                        $rzCode,

                    'quantity' =>
                        1,

                    'confirmedQuantity' =>
                        $status === 'new'
                            ? null
                            : 1,

                    'serialNumbers' =>
                        [],
                ],
            ],
        ];
    }

    /**
     * Посчитать HTTP-вызовы.
     *
     * @param array $calls
     * @param string $method
     * @param string $path
     * @return int
     */
    private function countCalls(
        array $calls,
        $method,
        $path
    ) {
        $count = 0;

        foreach ($calls as $call) {
            if (
                $call['method'] === $method &&
                $call['path'] === $path
            ) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param mixed $actual
     * @param mixed $expected
     * @param string $name
     * @return void
     */
    private function assertSame(
        $expected,
        $actual,
        $name
    ) {
        if ($expected !== $actual) {
            throw new RuntimeException(
                'TEST FAILED: ' .
                $name .
                '. Очікувалось: ' .
                var_export(
                    $expected,
                    true
                ) .
                ', отримано: ' .
                var_export(
                    $actual,
                    true
                )
            );
        }
    }

    /**
     * @param bool $condition
     * @param string $message
     * @return void
     */
    private function assertTrue(
        $condition,
        $message
    ) {
        if (!$condition) {
            throw new RuntimeException(
                'TEST FAILED: ' .
                $message
            );
        }
    }
}