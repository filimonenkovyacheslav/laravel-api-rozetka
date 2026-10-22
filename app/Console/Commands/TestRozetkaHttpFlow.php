<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\OrderTtnService;
use App\Services\Rozetka\RozetkaApiException;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use Illuminate\Http\Client\Factory as HttpFactory;

class TestRozetkaHttpFlow extends Command
{
    protected $signature =
        'rozetka:test-http-flow';

    protected $description =
        'Тест Rozetka HTTP flow через Http::fake без зовнішніх запитів';

    /**
     * RZ-код с существующими габаритами.
     *
     * @var string
     */
    private $rzCode = '493104624';

    public function handle()
    {
        /*
         * В .env ROZETKA_ACTIONS_ENABLED остаётся false.
         *
         * Только внутри этого artisan-процесса включаем
         * actions и подставляем полностью фейковый endpoint.
         */
        config([
            'services.rozetka.api_url' =>
                'https://fake-rozetka.test',

            'services.rozetka.jwt_token' =>
                'fake-test-token',

            'services.rozetka.actions_enabled' =>
                true,

            'services.rozetka.timeout' =>
                5,
        ]);

        DB::beginTransaction();

        try {
            $this->info(
                'Тест 1: new → confirmed → shipping'
            );

            $this->testHappyPath();

            $this->newLine();

            $this->info(
                'Тест 2: HTTP 500, але ТТН фактично збережена'
            );

            $this->test500WithReconciliation();

            $this->newLine();

            $this->info(
                'Тест 3: HTTP 500, стан ТТН невідомий'
            );

            $this->test500Uncertain();

            $this->newLine();

            $this->info(
                'Тест 4: HTTP 503, ТТН гарантовано не створена'
            );

            $this->test503();

            $this->newLine();

            $this->info(
                'Тест 5: HTTP 429 не викликає reconciliation GET'
            );

            $this->test429();

            $this->newLine();

            $this->info(
                'Усі fake HTTP-тести успішно пройдено.'
            );

            $this->line(
                'Жодного реального HTTP-запиту не виконано.'
            );

            $this->line(
                'Тестові записи будуть видалені rollback.'
            );

            return 0;
        } catch (Throwable $e) {
            $this->error(
                get_class($e) . ': ' .
                $e->getMessage()
            );

            return 1;
        } finally {
            DB::rollBack();
        }
    }

    /**
     * Полностью успешная цепочка:
     *
     * GET new
     * POST confirm
     * POST ttn
     */
    private function testHappyPath()
    {
        $order = $this->createTestOrder(
            'new',
            null,
            'HAPPY'
        );

        $guid = $order->guid;
        $supplierCode =
            $order->items->first()->supplier_code;

        $trackingNumber =
            '20400000000001';

        $calls = [];

        Http::swap(new HttpFactory());

        Http::fake(function (
            HttpRequest $request
        ) use (
            $guid,
            $supplierCode,
            $trackingNumber,
            &$calls
        ) {
            $path = $this->getPath(
                $request
            );

            $calls[] = [
                'method' => $request->method(),
                'path' => $path,
                'data' => $request->data(),
            ];

            if (
                $request->method() === 'GET' &&
                $path ===
                    '/dropshipping/orders/' .
                    $guid
            ) {
                return Http::response(
                    $this->makeOrderResponse(
                        $guid,
                        $supplierCode,
                        'new',
                        null,
                        null
                    ),
                    200
                );
            }

            if (
                $request->method() === 'POST' &&
                $path ===
                    '/dropshipping/orders/confirm/' .
                    $guid
            ) {
                $data = $request->data();

                $this->assertTrue(
                    isset(
                        $data['products'][0]
                            ['confirmedQuantity']
                    ) &&
                    (int) $data['products'][0]
                        ['confirmedQuantity'] === 1,
                    'Confirm payload містить неправильний confirmedQuantity.'
                );

                return Http::response([
                    'orderId' =>
                        $guid,

                    'orderStatus' =>
                        'confirmed',

                    'products' => [
                        [
                            'supplierCode' =>
                                $supplierCode,

                            'confirmedQuantity' =>
                                1,

                            'serialNumbers' =>
                                [],
                        ],
                    ],

                    'warnings' =>
                        [],
                ], 200);
            }

            if (
                $request->method() === 'POST' &&
                $path ===
                    '/dropshipping/orders/ttn/' .
                    $guid
            ) {
                $data = $request->data();

                $this->assertTrue(
                    isset(
                        $data['cargoSeats'][0]
                    ),
                    'У TTN payload немає cargoSeats.'
                );

                $seat =
                    $data['cargoSeats'][0];

                $this->assertTrue(
                    (float) $seat['lengthCm'] ===
                        190.0 &&
                    (float) $seat['widthCm'] ===
                        80.0 &&
                    (float) $seat['heightCm'] ===
                        10.0 &&
                    (float) $seat['weightKg'] ===
                        40.0,
                    'Габарити cargoSeats не відповідають очікуваним.'
                );

                return Http::response([
                    'orderId' =>
                        $guid,

                    'orderStatus' =>
                        'shipping',

                    'ttn' =>
                        $trackingNumber,

                    'shippingPoint' =>
                        'Fake shipping point',
                ], 200);
            }

            throw new RuntimeException(
                'Несподіваний HTTP-запит: ' .
                $request->method() .
                ' ' .
                $path
            );
        });

        $service =
            app(OrderTtnService::class);

        $result =
            $service->createForOrder(
                $order
            );

        $this->assertTrue(
            $result['tracking_number'] ===
                $trackingNumber,
            'Не отримано очікувану ТТН.'
        );

        $this->assertTrue(
            $this->countCalls(
                $calls,
                'GET',
                '/dropshipping/orders/' .
                    $guid
            ) === 1,
            'GET order мав бути виконаний 1 раз.'
        );

        $this->assertTrue(
            $this->countCalls(
                $calls,
                'POST',
                '/dropshipping/orders/confirm/' .
                    $guid
            ) === 1,
            'POST confirm мав бути виконаний рівно 1 раз.'
        );

        $this->assertTrue(
            $this->countCalls(
                $calls,
                'POST',
                '/dropshipping/orders/ttn/' .
                    $guid
            ) === 1,
            'POST TTN мав бути виконаний рівно 1 раз.'
        );

        $order->refresh();

        $this->assertTrue(
            $order->tracking_number ===
                $trackingNumber,
            'ТТН не збережена локально.'
        );

        $this->assertTrue(
            $order->rozetka_status ===
                'shipping',
            'Локальний статус не став shipping.'
        );

        $this->line(
            'OK: GET → confirm → TTN, без повторних POST.'
        );
    }

    /**
     * Rozetka отвечает 500 на POST TTN,
     * но при последующем GET ТТН уже существует.
     *
     * Повторный POST делать нельзя.
     */
    private function test500WithReconciliation()
    {
        $order = $this->createTestOrder(
            'confirmed',
            1,
            '500-RECOVERED'
        );

        $guid = $order->guid;
        $supplierCode =
            $order->items->first()->supplier_code;

        $trackingNumber =
            '20400000000002';

        $calls = [];
        $getCount = 0;

        Http::swap(new HttpFactory());

        Http::fake(function (
            HttpRequest $request
        ) use (
            $guid,
            $supplierCode,
            $trackingNumber,
            &$calls,
            &$getCount
        ) {
            $path = $this->getPath(
                $request
            );

            $calls[] = [
                'method' => $request->method(),
                'path' => $path,
            ];

            if (
                $request->method() === 'GET' &&
                $path ===
                    '/dropshipping/orders/' .
                    $guid
            ) {
                $getCount++;

                /*
                 * Первый GET: ТТН ещё нет.
                 *
                 * Второй GET после HTTP 500:
                 * Rozetka уже показывает созданную ТТН.
                 */
                if ($getCount >= 2) {
                    return Http::response(
                        $this->makeOrderResponse(
                            $guid,
                            $supplierCode,
                            'shipping',
                            1,
                            $trackingNumber
                        ),
                        200
                    );
                }

                return Http::response(
                    $this->makeOrderResponse(
                        $guid,
                        $supplierCode,
                        'confirmed',
                        1,
                        null
                    ),
                    200
                );
            }

            if (
                $request->method() === 'POST' &&
                $path ===
                    '/dropshipping/orders/ttn/' .
                    $guid
            ) {
                return Http::response([
                    'name' =>
                        'Internal Server Error',

                    'message' =>
                        'TTN was created in the delivery service but could not be saved.',

                    'code' =>
                        0,

                    'status' =>
                        500,
                ], 500);
            }

            throw new RuntimeException(
                'Несподіваний HTTP-запит: ' .
                $request->method() .
                ' ' .
                $path
            );
        });

        $service =
            app(OrderTtnService::class);

        $result =
            $service->createForOrder(
                $order
            );

        $this->assertTrue(
            $result['tracking_number'] ===
                $trackingNumber,
            'Reconciliation не знайшов існуючу ТТН.'
        );

        $this->assertTrue(
            $this->countCalls(
                $calls,
                'POST',
                '/dropshipping/orders/ttn/' .
                    $guid
            ) === 1,
            'Після HTTP 500 POST TTN не повинен повторюватись.'
        );

        $this->assertTrue(
            $getCount === 2,
            'Після HTTP 500 очікувався контрольний GET.'
        );

        $this->line(
            'OK: після 500 виконано GET, існуючу ТТН знайдено, POST не повторено.'
        );
    }

    /**
     * HTTP 500 и после контрольного GET
     * ТТН всё ещё отсутствует.
     *
     * Состояние неопределённое:
     * автоматически повторять нельзя.
     */
    private function test500Uncertain()
    {
        $order = $this->createTestOrder(
            'confirmed',
            1,
            '500-UNCERTAIN'
        );

        $guid = $order->guid;
        $supplierCode =
            $order->items->first()->supplier_code;

        $calls = [];

        Http::swap(new HttpFactory());

        Http::fake(function (
            HttpRequest $request
        ) use (
            $guid,
            $supplierCode,
            &$calls
        ) {
            $path = $this->getPath(
                $request
            );

            $calls[] = [
                'method' => $request->method(),
                'path' => $path,
            ];

            if (
                $request->method() === 'GET' &&
                $path ===
                    '/dropshipping/orders/' .
                    $guid
            ) {
                return Http::response(
                    $this->makeOrderResponse(
                        $guid,
                        $supplierCode,
                        'confirmed',
                        1,
                        null
                    ),
                    200
                );
            }

            if (
                $request->method() === 'POST' &&
                $path ===
                    '/dropshipping/orders/ttn/' .
                    $guid
            ) {
                return Http::response([
                    'name' =>
                        'Internal Server Error',

                    'message' =>
                        'TTN was created in the delivery service but could not be saved.',

                    'code' =>
                        0,

                    'status' =>
                        500,
                ], 500);
            }

            throw new RuntimeException(
                'Несподіваний HTTP-запит: ' .
                $request->method() .
                ' ' .
                $path
            );
        });

        $service =
            app(OrderTtnService::class);

        $caught = null;

        try {
            $service->createForOrder(
                $order
            );
        } catch (Throwable $e) {
            $caught = $e;
        }

        $this->assertTrue(
            $caught instanceof RuntimeException,
            'Очікувалась RuntimeException.'
        );

        $this->assertTrue(
            strpos(
                $caught->getMessage(),
                '[ROZETKA_TTN_UNCERTAIN]'
            ) !== false,
            'Помилка не позначена як ROZETKA_TTN_UNCERTAIN.'
        );

        $this->assertTrue(
            $this->countCalls(
                $calls,
                'POST',
                '/dropshipping/orders/ttn/' .
                    $guid
            ) === 1,
            'Небезпечний POST TTN був повторений.'
        );

        $this->line(
            'OK: стан позначено uncertain, автоматичного повтору немає.'
        );
    }

    /**
     * 503 означает, что накладная не создана.
     * Ошибка остаётся retryable.
     */
    private function test503()
    {
        $order = $this->createTestOrder(
            'confirmed',
            1,
            '503'
        );

        $guid = $order->guid;
        $supplierCode =
            $order->items->first()->supplier_code;

        $calls = [];

        Http::swap(new HttpFactory());

        Http::fake(function (
            HttpRequest $request
        ) use (
            $guid,
            $supplierCode,
            &$calls
        ) {
            $path = $this->getPath(
                $request
            );

            $calls[] = [
                'method' => $request->method(),
                'path' => $path,
            ];

            if (
                $request->method() === 'GET' &&
                $path ===
                    '/dropshipping/orders/' .
                    $guid
            ) {
                return Http::response(
                    $this->makeOrderResponse(
                        $guid,
                        $supplierCode,
                        'confirmed',
                        1,
                        null
                    ),
                    200
                );
            }

            if (
                $request->method() === 'POST' &&
                $path ===
                    '/dropshipping/orders/ttn/' .
                    $guid
            ) {
                return Http::response([
                    'name' =>
                        'Service Unavailable',

                    'message' =>
                        'Delivery service is temporarily unavailable. Please try again later.',

                    'code' =>
                        0,

                    'status' =>
                        503,
                ], 503);
            }

            throw new RuntimeException(
                'Несподіваний HTTP-запит: ' .
                $request->method() .
                ' ' .
                $path
            );
        });

        $service =
            app(OrderTtnService::class);

        $caught = null;

        try {
            $service->createForOrder(
                $order
            );
        } catch (Throwable $e) {
            $caught = $e;
        }

        $this->assertTrue(
            $caught instanceof
                RozetkaApiException,
            'Для 503 очікувалась RozetkaApiException.'
        );

        $this->assertTrue(
            $caught->getHttpStatus() ===
                503,
            'Втрачено HTTP status 503.'
        );

        $this->assertTrue(
            strpos(
                $caught->getMessage(),
                '[ROZETKA_TTN_UNCERTAIN]'
            ) === false,
            '503 помилково позначена як uncertain.'
        );

        $this->assertTrue(
            $this->countCalls(
                $calls,
                'POST',
                '/dropshipping/orders/ttn/' .
                    $guid
            ) === 1,
            'POST TTN повинен бути один.'
        );

        $this->line(
            'OK: 503 залишилась retryable-помилкою.'
        );
    }

    /**
     * HTTP 429 означает rate limit.
     *
     * После такого ответа не выполняем
     * дополнительный reconciliation GET,
     * чтобы не усугублять rate limit.
     */
    private function test429()
    {
        $order = $this->createTestOrder(
            'confirmed',
            1,
            '429'
        );

        $guid = $order->guid;
        $supplierCode =
            $order->items->first()->supplier_code;

        $calls = [];

        Http::swap(new HttpFactory());

        Http::fake(function (
            HttpRequest $request
        ) use (
            $guid,
            $supplierCode,
            &$calls
        ) {
            $path = $this->getPath(
                $request
            );

            $calls[] = [
                'method' => $request->method(),
                'path' => $path,
            ];

            if (
                $request->method() === 'GET' &&
                $path ===
                    '/dropshipping/orders/' .
                    $guid
            ) {
                return Http::response(
                    $this->makeOrderResponse(
                        $guid,
                        $supplierCode,
                        'confirmed',
                        1,
                        null
                    ),
                    200
                );
            }

            if (
                $request->method() === 'POST' &&
                $path ===
                    '/dropshipping/orders/ttn/' .
                    $guid
            ) {
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
            }

            throw new RuntimeException(
                'Несподіваний HTTP-запит: ' .
                $request->method() .
                ' ' .
                $path
            );
        });

        $service =
            app(OrderTtnService::class);

        $caught = null;

        try {
            $service->createForOrder(
                $order
            );
        } catch (Throwable $e) {
            $caught = $e;
        }

        $this->assertTrue(
            $caught instanceof
                RozetkaApiException,
            'Для 429 очікувалась RozetkaApiException.'
        );

        $this->assertTrue(
            $caught->getHttpStatus() ===
                429,
            'Втрачено HTTP status 429.'
        );

        $this->assertTrue(
            strpos(
                $caught->getMessage(),
                '[ROZETKA_TTN_UNCERTAIN]'
            ) === false,
            '429 помилково позначена як uncertain.'
        );

        $this->assertTrue(
            $this->countCalls(
                $calls,
                'GET',
                '/dropshipping/orders/' .
                    $guid
            ) === 1,
            'Після 429 не повинен виконуватись повторний GET.'
        );

        $this->assertTrue(
            $this->countCalls(
                $calls,
                'POST',
                '/dropshipping/orders/ttn/' .
                    $guid
            ) === 1,
            'POST TTN повинен бути виконаний рівно один раз.'
        );

        $this->line(
            'OK: 429 повернуто без повторного GET і без повторного POST.'
        );
    }

    /**
     * Создать временный заказ.
     */
    private function createTestOrder(
        $rozetkaStatus,
        $confirmedQuantity,
        $suffix
    ) {
        $guid =
            (string) Str::uuid();

        $order = Order::create([
            'guid' =>
                $guid,

            'order_num' =>
                'LOCAL-HTTP-' .
                $suffix .
                '-' .
                time(),

            'comment' =>
                'Local fake HTTP test',

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
                now(),

            'updated_at_partner' =>
                now(),

            'rozetka_synced_at' =>
                now(),
        ]);

        $order->items()->create([
            'supplier_code' =>
                'LOCAL-HTTP-' .
                $suffix,

            'rz_code' =>
                $this->rzCode,

            'quantity' =>
                1,

            'confirmed_quantity' =>
                $confirmedQuantity,

            'price' =>
                null,

            'reserved_quantity' =>
                1,

            'serial_number' =>
                json_encode([]),
        ]);

        return $order->fresh([
            'items',
        ]);
    }

    /**
     * Сделать fake GET /order response.
     */
    private function makeOrderResponse(
        $guid,
        $supplierCode,
        $status,
        $confirmedQuantity,
        $ttn
    ) {
        return [
            'orderId' =>
                $guid,

            'orderStatus' =>
                $status,

            'createdAt' =>
                now()->format(
                    'Y-m-d H:i:s'
                ),

            'updatedAt' =>
                now()->format(
                    'Y-m-d H:i:s'
                ),

            'orderNum' =>
                'LOCAL-HTTP',

            'deliveryAddressType' =>
                'DropShipping',

            'cashOnDelivery' =>
                0,

            'deliveryCompanyName' =>
                'Nova Poshta',

            'deliveryAddressId' =>
                null,

            'customerName' =>
                'Local Test',

            'deliveryCity' =>
                'Kyiv',

            'deliveryStreet' =>
                null,

            'deliveryPhone' =>
                '+380000000000',

            'ttn' =>
                $ttn,

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
                        (int) $this->rzCode,

                    'quantity' =>
                        1,

                    'confirmedQuantity' =>
                        $confirmedQuantity,

                    'serialNumbers' =>
                        [],
                ],
            ],
        ];
    }

    /**
     * Получить path без fake host.
     */
    private function getPath(
        HttpRequest $request
    ) {
        return (string) parse_url(
            $request->url(),
            PHP_URL_PATH
        );
    }

    /**
     * Посчитать конкретные HTTP-вызовы.
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
     * Простая assertion без PHPUnit.
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

    /**
     * Сбросить fake HTTP state между тестовыми сценариями.
     *
     * @return void
     */
    private function resetHttp()
    {
        Http::swap(
            new HttpFactory()
        );
    }
}