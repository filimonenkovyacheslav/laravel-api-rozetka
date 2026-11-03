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
                'Тест 1: confirmed → shipping без POST confirm'
            );

            $this->testHappyPath();

            $this->newLine();

            $this->info(
                'Тест 1.1: new/updated вимагають явного підтвердження до ТТН'
            );
            $this->testTtnRequiresConfirmation();

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

            $this->info('Тест 6: перевірка статусу ЮЗД перед TTN');
            $this->testUzdGuard();

            $this->newLine();

            $this->info(
                'Тест 7: підтвердження всієї кількості та захист від повторного POST'
            );
            $this->testConfirmFullOrder();

            $this->newLine();

            $this->info(
                'Тест 7.1: часткове підтвердження, warnings та повторна зміна в confirmed'
            );
            $this->testPartialConfirmation();

            $this->newLine();

            $this->info('Тест 8: статуси Rozetka та ЮЗД мають правильні підписи');
            $this->testStatusPresenters();

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
     * GET confirmed
     * POST ttn (without implicit confirmation)
     */
    private function testHappyPath()
    {
        $order = $this->createTestOrder(
            'confirmed',
            1,
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
            ) === 0,
            'Створення ТТН не повинно відправляти POST confirm.'
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
            'OK: confirmed → TTN, без неявного POST confirm.'
        );
    }

    /**
     * Creating a TTN on new/updated orders must NOT implicitly
     * confirm the order. Only the explicit confirm-all action may POST.
     */
    private function testTtnRequiresConfirmation()
    {
        foreach (['new', 'updated'] as $status) {
            $order = $this->createTestOrder(
                $status,
                null,
                'REQUIRES-CONFIRM-' . strtoupper($status)
            );

            $calls = [];
            $guid = $order->guid;
            $supplierCode = $order->items->first()->supplier_code;

            $this->resetHttp();

            Http::fake(function (HttpRequest $request) use (
                $guid,
                $supplierCode,
                $status,
                &$calls
            ) {
                $path = $this->getPath($request);
                $calls[] = [$request->method(), $path];

                if (
                    $request->method() === 'GET' &&
                    $path === '/dropshipping/orders/' . $guid
                ) {
                    return Http::response(
                        $this->makeOrderResponse(
                            $guid, $supplierCode, $status, null, null
                        ),
                        200
                    );
                }

                throw new RuntimeException(
                    'Implicit API POST forbidden: ' .
                    $request->method() . ' ' . $path
                );
            });

            $blocked = false;

            try {
                app(OrderTtnService::class)->createForOrder($order);
            } catch (RuntimeException $e) {
                $blocked = strpos(
                    $e->getMessage(), 'Підтвердити всю кількість'
                ) !== false;
            }

            $this->assertTrue(
                $blocked,
                'Не заблоковано ТТН до підтвердження статусу ' . $status
            );
            $this->assertTrue(
                $calls === [['GET', '/dropshipping/orders/' . $guid]],
                'Зайвий POST під час створення ТТН зі статусу ' . $status
            );
        }

        $this->line('OK: new/updated блокуються до явного підтвердження.');
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
     * Статус ЮЗД блокує ТТН, але не підтвердження замовлення.
     * Жоден реальний HTTP-запит не виконується.
     */
    private function testUzdGuard()
    {
        foreach (['not_uploaded', 'problem', null] as $status) {
            $order = $this->createTestOrder(
                'confirmed',
                1,
                'UZD-' . ($status === null ? 'NULL' : $status)
            );
            $guid = $order->guid;
            $supplierCode = $order->items->first()->supplier_code;
            $calls = [];

            Http::swap(new HttpFactory());
            Http::fake(function (HttpRequest $request) use (
                $guid,
                $supplierCode,
                $status,
                &$calls
            ) {
                $path = $this->getPath($request);
                $calls[] = [$request->method(), $path];

                $this->assertTrue(
                    $request->method() === 'GET' &&
                    $path === '/dropshipping/orders/' . $guid,
                    'Для confirmed із непідтвердженим ЮЗД очікується тільки GET.'
                );

                $response = $this->makeOrderResponse(
                    $guid,
                    $supplierCode,
                    'confirmed',
                    1,
                    null
                );
                $response['uzdStatus'] = $status;

                return Http::response($response, 200);
            });

            $blocked = false;

            try {
                app(OrderTtnService::class)->createForOrder($order);
            } catch (RuntimeException $e) {
                $blocked = strpos($e->getMessage(), 'ЮЗД') !== false;
            }

            $this->assertTrue($blocked, 'Не заблоковано ТТН за ЮЗД.');
            $this->assertTrue(
                count($calls) === 1 && $calls[0] === [
                    'GET',
                    '/dropshipping/orders/' . $guid,
                ],
                'Для confirmed із неготовим ЮЗД має бути тільки один GET.'
            );

            $order->refresh();
            $this->assertTrue(
                $order->uzd_status === $status,
                'Не збережено uzdStatus із GET.'
            );
        }

        // A new order can be confirmed explicitly even while UZD
        // is not uploaded. TTN must still be blocked.
        $order = $this->createTestOrder('new', null, 'UZD-NEW');
        $guid = $order->guid;
        $supplierCode = $order->items->first()->supplier_code;
        $calls = [];
        $remoteStatus = 'new';

        $this->resetHttp();

        Http::fake(function (HttpRequest $request) use (
            $guid,
            $supplierCode,
            &$remoteStatus,
            &$calls
        ) {
            $path = $this->getPath($request);
            $calls[] = [$request->method(), $path];

            if (
                $request->method() === 'GET' &&
                $path === '/dropshipping/orders/' . $guid
            ) {
                $response = $this->makeOrderResponse(
                    $guid,
                    $supplierCode,
                    $remoteStatus,
                    $remoteStatus === 'confirmed' ? 1 : null,
                    null
                );
                $response['uzdStatus'] = 'not_uploaded';

                return Http::response($response, 200);
            }

            if (
                $request->method() === 'POST' &&
                $path === '/dropshipping/orders/confirm/' . $guid
            ) {
                $remoteStatus = 'confirmed';

                return Http::response([
                    'orderId' => $guid,
                    'orderStatus' => 'confirmed',
                    'products' => [[
                        'supplierCode' => $supplierCode,
                        'confirmedQuantity' => 1,
                        'serialNumbers' => [],
                    ]],
                    'warnings' => [],
                ], 200);
            }

            throw new RuntimeException(
                'Несподіваний запит за непідтвердженого ЮЗД: ' .
                $request->method() . ' ' . $path
            );
        });

        // This is the action behind the green confirm-all button.
        $confirmation = app(
            \App\Services\Rozetka\RozetkaFulfillmentService::class
        )->confirmFullOrder($order);

        $this->assertTrue(
            $confirmation['performed'] === true &&
            $confirmation['order']->rozetka_status === 'confirmed',
            'Явне підтвердження нового замовлення не виконалось.'
        );

        $blocked = false;

        try {
            app(OrderTtnService::class)->createForOrder(
                $confirmation['order']
            );
        } catch (RuntimeException $e) {
            $blocked = strpos($e->getMessage(), 'ЮЗД') !== false;
        }

        $this->assertTrue(
            $blocked,
            'ТТН має блокуватись за непідтвердженого ЮЗД.'
        );

        $this->assertTrue(
            $calls === [
                ['GET', '/dropshipping/orders/' . $guid],
                ['POST', '/dropshipping/orders/confirm/' . $guid],
                ['GET', '/dropshipping/orders/' . $guid],
                ['GET', '/dropshipping/orders/' . $guid],
            ],
            'Потрібне одне явне підтвердження й жодного POST TTN.'
        );

        $order->refresh();

        $this->assertTrue(
            $order->rozetka_status === 'confirmed' &&
            $order->uzd_status === 'not_uploaded',
            'Статуси після явного підтвердження невірні.'
        );

        $this->line(
            'OK: явний confirm дозволено, TTN блокується до verified UZD.'
        );
    }

    /**
     * A confirmation button must confirm quantities remotely exactly once.
     * No TTN should be created and a second click must be blocked.
     */
    private function testConfirmFullOrder()
    {
        $order = $this->createTestOrder('new', null, 'CONFIRM-ALL');
        $guid = $order->guid;
        $supplierCode = $order->items->first()->supplier_code;

        // Default reservation equals the ordered quantity.

        $calls = [];
        $getCount = 0;

        $this->resetHttp();

        Http::fake(function (HttpRequest $request) use (
            $guid,
            $supplierCode,
            &$calls,
            &$getCount
        ) {
            $path = $this->getPath($request);
            $method = $request->method();

            $calls[] = [
                'method' => $method,
                'path' => $path,
            ];

            if ($method === 'GET' &&
                $path === '/dropshipping/orders/' . $guid
            ) {
                $getCount++;

                return Http::response(
                    $this->makeOrderResponse(
                        $guid,
                        $supplierCode,
                        $getCount === 1 ? 'new' : 'confirmed',
                        $getCount === 1 ? null : 1,
                        null
                    ),
                    200
                );
            }

            if ($method === 'POST' &&
                $path === '/dropshipping/orders/confirm/' . $guid
            ) {
                $payload = $request->data();

                $this->assertTrue(
                    (int) ($payload['products'][0]['confirmedQuantity'] ?? 0) === 1,
                    'Кнопка повинна надіслати всю замовлену кількість.'
                );

                return Http::response([
                    'orderId' => $guid,
                    'orderStatus' => 'confirmed',
                    'products' => [[
                        'supplierCode' => $supplierCode,
                        'confirmedQuantity' => 1,
                        'serialNumbers' => [],
                    ]],
                ], 200);
            }

            throw new RuntimeException('Unexpected API request: ' . $method . ' ' . $path);
        });

        $service = app(\App\Services\Rozetka\RozetkaFulfillmentService::class);

        $firstResult = $service->confirmFullOrder($order);
        $confirmed = $firstResult['order'];

        $this->assertTrue(
            $firstResult['performed'] === true &&
            $confirmed->rozetka_status === 'confirmed' &&
            (int) $confirmed->items->first()->confirmed_quantity === 1,
            'Кількість за замовчуванням не підтвердилась через Rozetka.'
        );

        $secondResult = $service->confirmFullOrder($confirmed);

        $this->assertTrue(
            $secondResult['performed'] === false,
            'Повтор без змін має завершуватись без POST.'
        );

        $this->assertTrue(
            $this->countCalls(
                $calls,
                'POST',
                '/dropshipping/orders/confirm/' . $guid
            ) === 1,
            'Повторний POST confirm виконано.'
        );

        $this->assertTrue(
            $this->countCalls(
                $calls,
                'POST',
                '/dropshipping/orders/ttn/' . $guid
            ) === 0,
            'Підтвердження товарів не повинно створювати ТТН.'
        );

        $this->line('OK: один POST confirm, повтор заблокований, TTN не створено.');
    }

    /**
     * The Rozetka contract confirms all products in one payload.
     * A partial quantity is HTTP 200 with warnings, and it can be
     * amended while orderStatus=confirmed. Shipping remains locked.
     */
    private function testPartialConfirmation()
    {
        $order = $this->createTestOrder('new', null, 'PARTIAL-CONFIRM');
        $first = $order->items->first();
        $firstCode = $first->supplier_code;
        $secondCode = 'LOCAL-HTTP-SECOND-' . $order->id;

        $second = $order->items()->create([
            'supplier_code' => $secondCode,
            'rz_code' => $this->rzCode,
            'quantity' => 2,
            'confirmed_quantity' => null,
            'reserved_quantity' => 2,
            'serial_number' => json_encode([]),
            'price' => null,
        ]);

        $status = 'new';
        $remoteValues = [
            $firstCode => null,
            $secondCode => null,
        ];

        $remoteSerials = [
            $firstCode => [],
            $secondCode => [],
        ];

        $posts = 0;
        $gets = 0;
        $postPayloads = [];

        $this->resetHttp();

        Http::fake(function (HttpRequest $request) use (
            $order,
            $firstCode,
            $secondCode,
            &$status,
            &$remoteValues,
            &$remoteSerials,
            &$posts,
            &$gets,
            &$postPayloads
        ) {
            $path = $this->getPath($request);
            $method = $request->method();

            if ($method === 'GET' &&
                $path === '/dropshipping/orders/' . $order->guid
            ) {
                $gets++;

                $result = $this->makeOrderResponse(
                    $order->guid,
                    $firstCode,
                    $status,
                    $remoteValues[$firstCode],
                    $status === 'shipping' ? '20400557861920' : null
                );

                $result['products'] = [
                    [
                        'supplierCode' => $firstCode,
                        'rzCode' => (int) $this->rzCode,
                        'quantity' => 1,
                        'confirmedQuantity' => $remoteValues[$firstCode],
                        'serialNumbers' => $remoteSerials[$firstCode],
                    ],
                    [
                        'supplierCode' => $secondCode,
                        'rzCode' => (int) $this->rzCode,
                        'quantity' => 2,
                        'confirmedQuantity' => $remoteValues[$secondCode],
                        'serialNumbers' => $remoteSerials[$secondCode],
                    ],
                ];

                return Http::response($result, 200);
            }

            if ($method === 'POST' &&
                $path === '/dropshipping/orders/confirm/' . $order->guid
            ) {
                $posts++;
                $products = $request->data()['products'] ?? [];
                $postPayloads[] = $products;

                $this->assertTrue(
                    count($products) === 2 &&
                    $products[0]['supplierCode'] === $firstCode &&
                    $products[1]['supplierCode'] === $secondCode,
                    'POST confirm повинен містити всі позиції замовлення.'
                );

                foreach ($products as $product) {
                    $code = $product['supplierCode'];
                    $newQuantity = (int) $product['confirmedQuantity'];

                    // Simulate Rozetka clearing SN when the quantity changes.
                    if (
                        $remoteValues[$code] !== null &&
                        (int) $remoteValues[$code] !== $newQuantity
                    ) {
                        $remoteSerials[$code] = [];
                    }

                    $remoteValues[$code] = $newQuantity;
                }

                $status = 'confirmed';

                $warnings = [];
                foreach ($products as $product) {
                    $ordered = $product['supplierCode'] === $firstCode ? 1 : 2;

                    if ((int) $product['confirmedQuantity'] < $ordered) {
                        $warnings[] = [
                            'supplierCode' => $product['supplierCode'],
                            'message' => 'Підтверджено частково',
                        ];
                    }
                }

                return Http::response([
                    'orderId' => $order->guid,
                    'orderStatus' => 'confirmed',
                    'products' => $products,
                    'warnings' => $warnings,
                ], 200);
            }

            throw new RuntimeException(
                'Unexpected partial-confirmation request: ' .
                $method . ' ' . $path
            );
        });

        $service = app(\App\Services\Rozetka\RozetkaFulfillmentService::class);

        // Individual "Зарезервувати": save only a local draft.
        $first->update(['reserved_quantity' => 0]);

        $this->assertTrue(
            $posts === 0,
            'Резервування однієї позиції не повинно робити POST.'
        );

        // Only the order-wide button sends all staged quantities.
        $partial = $service->confirmFullOrder($order);

        $this->assertTrue(
            $partial['performed'] === true &&
            $partial['partial'] === true &&
            count($partial['warnings']) === 1,
            'HTTP 200 з warnings має бути успішним частковим підтвердженням.'
        );

        $this->assertTrue(
            $posts === 1 &&
            (int) $postPayloads[0][0]['confirmedQuantity'] === 0 &&
            (int) $postPayloads[0][1]['confirmedQuantity'] === 2,
            'Неправильний повний payload часткового підтвердження.'
        );

        $partial['order']->refresh();
        $this->assertTrue(
            $partial['order']->rozetka_status === 'confirmed' &&
            (int) $partial['order']->items->first()->confirmed_quantity === 0,
            'Після warnings замовлення має перейти в confirmed.'
        );

        $blocked = false;
        try {
            $service->buildCargoSeats($partial['order']);
        } catch (RuntimeException $e) {
            $blocked = strpos($e->getMessage(), 'ще не підтверджено повністю') !== false;
        }

        $this->assertTrue(
            $blocked,
            'Неповне confirmedQuantity повинно блокувати створення ТТН.'
        );

        // Simulate serials saved on a fully confirmed item by another
        // operator. Changing its quantity must clear these SNs.
        $remoteSerials[$secondCode] = ['SN-001', 'SN-002'];

        // A second partial change in confirmed should return two warnings.
        $second->update(['reserved_quantity' => 1]);
        $this->assertTrue(
            $posts === 1,
            'Індивідуальне резервування не повинно додавати POST.'
        );

        $partialAgain = $service->confirmFullOrder($partial['order']);

        $this->assertTrue(
            $partialAgain['performed'] === true &&
            $partialAgain['partial'] === true &&
            count($partialAgain['warnings']) === 2 &&
            $posts === 2 &&
            (int) $postPayloads[1][0]['confirmedQuantity'] === 0 &&
            (int) $postPayloads[1][1]['confirmedQuantity'] === 1,
            'Кожен недопідтверджений товар повинен мати warning.'
        );

        $this->assertTrue(
            ($postPayloads[1][1]['serialNumbers'] ?? null) === [] &&
            json_decode(
                (string) $partialAgain['order']->items
                    ->firstWhere('supplier_code', $secondCode)->serial_number,
                true
            ) === [],
            'Зміна кількості має скидати наявні серійні номери.'
        );

        // Restore each staged quantity to the full ordered amount.
        $first->update(['reserved_quantity' => 1]);
        $second->update(['reserved_quantity' => 2]);

        // Even confirmed orders allow quantity changes before TTN.
        $full = $service->confirmFullOrder($partialAgain['order']);

        $this->assertTrue(
            $full['performed'] === true &&
            $full['partial'] === false &&
            $posts === 3 &&
            (int) $postPayloads[2][0]['confirmedQuantity'] === 1 &&
            (int) $postPayloads[2][1]['confirmedQuantity'] === 2,
            'confirmed має дозволяти допідтвердження до повної кількості.'
        );

        $this->assertTrue(
            ($postPayloads[2][0]['serialNumbers'] ?? null) === [] &&
            ($postPayloads[2][1]['serialNumbers'] ?? null) === [],
            'Зміна підтвердженої кількості повинна скидати серійні номери.'
        );

        // Reconfirming unchanged quantities must not emit another POST.
        $noop = $service->confirmFullOrder($full['order']);
        $this->assertTrue(
            $noop['performed'] === false && $posts === 3,
            'Незмінне confirmed не повинно створювати додатковий POST.'
        );

        // After an external update even already full quantities need POST.
        $status = 'updated';
        $updated = $service->confirmFullOrder($full['order']);

        $this->assertTrue(
            $updated['performed'] === true &&
            $updated['partial'] === false &&
            $posts === 4,
            'Статус updated повинен дозволяти повторне підтвердження.'
        );

        $status = 'shipping';
        $blocked = false;

        try {
            $service->confirmFullOrder($updated['order']);
        } catch (RuntimeException $e) {
            $blocked = strpos($e->getMessage(), 'new, updated та confirmed') !== false;
        }

        $this->assertTrue(
            $blocked && $posts === 4,
            'У shipping зміна кількості повинна блокуватися до POST.'
        );

        $this->line(
            'OK: partial 200/warnings per product, всі products, TTN guard, ' .
            'confirmed/updated edits, no duplicate POST, shipping guard.'
        );
    }

    private function testStatusPresenters()
    {
        $statusLabels = [
            'new' => ['Нове', '#337ab7'],
            'updated' => ['Оновлено', '#ffc107'],
            'deleted' => ['Скасовано', '#d9534f'],
            'confirmed' => ['Підтверджено', '#5cb85c'],
            'shipping' => ['Доставка', '#5bc0de'],
            'closed' => ['Закрито', '#333333'],
            'delivery_refused' => ['Відмова від отримання', '#a94442'],
            'problem' => ['Проблема', '#a94442'],
            'shipped_from_rozetka' => ['Доставка від Розетки', '#5bc0de'],
        ];

        $this->assertTrue(
            count(\App\Support\RozetkaStatusPresenter::orderStatuses()) === 9,
            'Має бути рівно 9 статусів Rozetka.'
        );

        foreach ($statusLabels as $code => $values) {
            $meta = \App\Support\RozetkaStatusPresenter::orderStatus($code);

            $this->assertTrue(
                ($meta['label'] ?? null) === $values[0] &&
                ($meta['color'] ?? null) === $values[1],
                'Неправильний підпис/колір Rozetka: ' . $code
            );
        }

        foreach ([
            null => 'Невідомий',
            'not_uploaded' => 'Не завантажено',
            'verified' => 'Пройшло звірку',
            'problem' => 'Є проблеми',
            'skipped' => 'Перевірку пропущено',
        ] as $code => $label) {
            $this->assertTrue(
                \App\Support\RozetkaStatusPresenter::uzdLabel($code) === $label,
                'Неправильний статус Вчасно: ' . (string) $code
            );
        }

        $this->line('OK: 9 статусів Rozetka та 5 варіантів ЮЗД.');
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

            'uzdStatus' =>
                'verified',

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