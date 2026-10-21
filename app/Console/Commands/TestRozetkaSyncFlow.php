<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\Rozetka\RozetkaApiException;
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
        'Локальний тест синхронізації Rozetka через expanded list-response';

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
                'Тест синхронізації Rozetka через expand'
            );

            $this->newLine();

            $this->info(
                'Тест 1: expand=details,products без detail GET'
            );

            $this->testExpandedSynchronization();

            $this->newLine();

            $this->info(
                'Тест 2: HTTP 429 не повторюється та не є transport error'
            );

            $this->test429IsNotRetried();

            $this->newLine();

            $this->info(
                'Усі fake HTTP-тести синхронізації успішно пройдено.'
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
     * Проверить новый основной сценарий:
     *
     * GET /dropshipping/orders
     *     ?expand=details,products
     *
     * должен сразу вернуть данные,
     * достаточные для синхронизации.
     *
     * Ни одного GET /orders/{orderId}
     * выполняться не должно.
     *
     * @return void
     */
    private function testExpandedSynchronization()
    {
        /*
         * =====================================================
         * 1. НЕИЗМЕНЁННЫЙ заказ
         * =====================================================
         *
         * status и updatedAt полностью совпадают.
         * Его не сохраняем повторно.
         */
        $unchangedGuid =
            (string) Str::uuid();

        $unchanged =
            $this->createExistingOrder(
                $unchangedGuid,
                'confirmed',
                '2026-10-10 10:00:00',
                'UNCHANGED'
            );

        /*
         * Фиксируем synced_at, чтобы проверить,
         * что skipped-заказ не был затронут.
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
         * В API будет более новый updatedAt.
         */
        $changedGuid =
            (string) Str::uuid();

        $changed =
            $this->createExistingOrder(
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
         * В локальной БД его нет.
         */
        $newGuid =
            (string) Str::uuid();

        $calls = [];

        /*
         * Laravel 8 может накапливать fake handlers.
         * Создаём чистую HTTP factory.
         */
        $this->resetHttp();

        Http::fake(function (
            HttpRequest $request
        ) use (
            $unchangedGuid,
            $changedGuid,
            $newGuid,
            &$calls
        ) {
            $url =
                $request->url();

            $path = (string) parse_url(
                $url,
                PHP_URL_PATH
            );

            $queryString =
                (string) parse_url(
                    $url,
                    PHP_URL_QUERY
                );

            $query = [];

            parse_str(
                $queryString,
                $query
            );

            $calls[] = [
                'method' =>
                    $request->method(),

                'path' =>
                    $path,

                'query' =>
                    $query,

                'data' =>
                    $request->data(),
            ];

            /*
             * Любой detail GET теперь является ошибкой
             * теста. Новая схема должна работать
             * только через expanded list-response.
             */
            if (
                $request->method() === 'GET' &&
                strpos(
                    $path,
                    '/dropshipping/orders/'
                ) === 0
            ) {
                throw new RuntimeException(
                    'TEST FAILED: виконано зайвий detail GET: ' .
                    $path
                );
            }

            /*
             * Единственный допустимый запрос.
             */
            if (
                $request->method() === 'GET' &&
                $path === '/dropshipping/orders'
            ) {
                $this->assertSame(
                    'details,products',
                    $query['expand'] ?? null,
                    'expand'
                );

                $this->assertSame(
                    '2026-09-12 00:00:00',
                    $query['dateFrom'] ?? null,
                    'dateFrom'
                );

                $this->assertSame(
                    '2026-10-12 23:59:59',
                    $query['dateTo'] ?? null,
                    'dateTo'
                );

                $this->assertSame(
                    '1',
                    isset($query['page'])
                        ? (string) $query['page']
                        : null,
                    'page'
                );

                return Http::response([
                    'totalCount' => 3,
                    'currentPage' => 1,

                    'orders' => [
                        /*
                         * Полностью совпадает
                         * с локальным состоянием.
                         */
                        $this->makeExpandedOrder(
                            $unchangedGuid,
                            'РОЗ-LOCAL-UNCHANGED',
                            'confirmed',
                            '2026-10-09 10:00:00',
                            '2026-10-10 10:00:00',
                            'LOCAL-SYNC-UNCHANGED',
                            493104624
                        ),

                        /*
                         * updatedAt и status изменились.
                         */
                        $this->makeExpandedOrder(
                            $changedGuid,
                            'РОЗ-LOCAL-CHANGED',
                            'updated',
                            '2026-10-09 11:00:00',
                            '2026-10-12 12:30:00',
                            'LOCAL-SYNC-CHANGED',
                            493104624
                        ),

                        /*
                         * Новый заказ.
                         */
                        $this->makeExpandedOrder(
                            $newGuid,
                            'РОЗ-LOCAL-NEW',
                            'new',
                            '2026-10-12 13:00:00',
                            null,
                            'LOCAL-SYNC-NEW',
                            493104624
                        ),
                    ],
                ], 200);
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
         * Проверяем HTTP.
         * =====================================================
         *
         * Теперь должен быть ровно один list GET.
         */
        $this->assertSame(
            1,
            $this->countCalls(
                $calls,
                'GET',
                '/dropshipping/orders'
            ),
            'GET expanded list'
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
            0,
            $this->countCalls(
                $calls,
                'GET',
                '/dropshipping/orders/' .
                    $changedGuid
            ),
            'detail GET changed'
        );

        $this->assertSame(
            0,
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

        $this->assertTrue(
            is_array(
                $newOrder->items
                    ->first()
                    ->rozetka_cargo_seats
            ),
            'cargoSeats Rozetka не збережено.'
        );

        $this->assertSame(
            1,
            count(
                $newOrder->items
                    ->first()
                    ->rozetka_cargo_seats
            ),
            'Кількість збережених cargoSeats'
        );

        $newItem = $newOrder->items->first();

        $this->assertTrue(
            is_array($newItem->rozetka_cargo_seats),
            'cargoSeats Rozetka не збережено в order_items.'
        );

        $this->assertSame(
            1,
            count($newItem->rozetka_cargo_seats),
            'Некоректна кількість збережених cargoSeats.'
        );

        $this->assertSame(
            30,
            $newItem->rozetka_cargo_seats[0]['lengthCm'],
            'Некоректно збережено lengthCm.'
        );

        $this->assertSame(
            20,
            $newItem->rozetka_cargo_seats[0]['widthCm'],
            'Некоректно збережено widthCm.'
        );

        $this->assertSame(
            10,
            $newItem->rozetka_cargo_seats[0]['heightCm'],
            'Некоректно збережено heightCm.'
        );

        $this->assertSame(
            1.5,
            (float) $newItem->rozetka_cargo_seats[0]['weightKg'],
            'Некоректно збережено weightKg.'
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

        $this->line(
            'OK: один expanded GET повернув details + products.'
        );

        $this->line(
            'OK: новий та змінений закази синхронізовано без detail GET.'
        );

        $this->line(
            'OK: незмінений заказ пропущено без запису.'
        );
    }

    /**
     * Проверить найденный на реальном API случай:
     *
     * HTTP 429 должен:
     * - остаться HTTP-ошибкой;
     * - иметь httpStatus=429;
     * - transportError=false;
     * - не вызывать автоматических повторов.
     *
     * @return void
     */
    private function test429IsNotRetried()
    {
        $calls = 0;

        $this->resetHttp();

        Http::fake(function (
            HttpRequest $request
        ) use (&$calls) {
            $calls++;

            $path = (string) parse_url(
                $request->url(),
                PHP_URL_PATH
            );

            if (
                $request->method() !== 'GET' ||
                $path !== '/dropshipping/orders'
            ) {
                throw new RuntimeException(
                    'TEST FAILED: несподіваний HTTP-запит у тесті 429.'
                );
            }

            return Http::response([
                'name' =>
                    'Too Many Requests',

                'message' =>
                    'Too many requests. Please try again later.',

                'code' =>
                    0,

                'status' =>
                    429,
            ], 429);
        });

        $service =
            app(RozetkaOrderService::class);

        $caught = null;

        try {
            /*
             * dryRun здесь используется только затем,
             * чтобы тест вообще не зависел от sync flag.
             *
             * До БД выполнение всё равно не дойдёт.
             */
            $service->sync(
                [
                    'dateFrom' =>
                        '2026-10-15 00:00:00',

                    'dateTo' =>
                        '2026-10-21 23:59:59',
                ],
                true
            );
        } catch (RozetkaApiException $e) {
            $caught = $e;
        }

        $this->assertTrue(
            $caught instanceof RozetkaApiException,
            'HTTP 429 не повернув RozetkaApiException.'
        );

        $this->assertSame(
            429,
            $caught->getHttpStatus(),
            'HTTP status для 429'
        );

        $this->assertSame(
            false,
            $caught->isTransportError(),
            'transportError для HTTP 429'
        );

        $this->assertSame(
            1,
            $calls,
            'Кількість HTTP-викликів при 429'
        );

        $this->assertTrue(
            strpos(
                $caught->getMessage(),
                'HTTP 429'
            ) !== false,
            'У повідомленні немає HTTP 429.'
        );

        $this->line(
            'OK: HTTP 429 розпізнано коректно.'
        );

        $this->line(
            'OK: 429 не викликав автоматичного retry.'
        );
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
     * Fake expanded list-order.
     *
     * Имитирует элемент:
     *
     * GET /dropshipping/orders
     *     ?expand=details,products
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
    private function makeExpandedOrder(
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

            'createdAt' =>
                $createdAt,

            'updatedAt' =>
                $updatedAt,

            'orderStatus' =>
                $status,

            'uzdStatus' =>
                'verified',

            'itemsCount' =>
                1,

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
                false,

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

                    /*
                     * Новое поле в свежей схеме.
                     *
                     * Сейчас локальная синхронизация
                     * его не сохраняет, но ответ API
                     * должен корректно приниматься.
                     */
                    'cargoSeats' => [
                        [
                            'lengthCm' =>
                                30,

                            'widthCm' =>
                                20,

                            'heightCm' =>
                                10,

                            'weightKg' =>
                                1.5,

                            'placeDescription' =>
                                'Основне місце',
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Создать полностью чистый Http fake context.
     *
     * @return void
     */
    private function resetHttp()
    {
        Http::swap(
            new HttpFactory()
        );
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