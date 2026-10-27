<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\TtnBatch;
use App\Models\TtnBatchOrder;
use App\Services\Rozetka\RozetkaApiException;
use App\Services\Rozetka\RozetkaMarkingService;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class TestRozetkaMarkingsFlow extends Command
{
    protected $signature =
        'rozetka:test-markings-flow';

    protected $description =
        'Локальний fake HTTP-тест друку маркувань Rozetka';

    public function handle()
    {
        $oldApiUrl = config(
            'services.rozetka.api_url'
        );

        $oldToken = config(
            'services.rozetka.jwt_token'
        );

        $oldActionsEnabled = config(
            'services.rozetka.actions_enabled'
        );

        config([
            'services.rozetka.api_url' =>
                'https://fake-rozetka-markings.test',

            'services.rozetka.jwt_token' =>
                'fake-markings-token',

            'services.rozetka.actions_enabled' =>
                true,

            'services.rozetka.timeout' =>
                5,
        ]);

        DB::beginTransaction();

        try {
            $this->info(
                'Тест 1: змішаний пакет → тільки новий Rozetka API'
            );

            $this->testMixedBatch();

            $this->newLine();

            $this->info(
                'Тест 2: ROZETKA_ACTIONS_ENABLED=false'
            );

            $this->testActionsDisabled();

            $this->newLine();

            $this->info(
                'Тест 3: HTTP 400 повертається як RozetkaApiException'
            );

            $this->testHttp400();

            $this->newLine();

            $this->info(
                'Тест 4: HTTP 200, але частина маркувань пропущена'
            );

            $this->testPartialMarkings();

            $this->newLine();

            $this->info(
                'Тест 5: більше 100 маркувань'
            );

            $this->testMoreThan100Orders();

            $this->newLine();

            $this->info(
                'Усі fake HTTP-тести маркувань успішно пройдено.'
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
                get_class($e) .
                ': ' .
                $e->getMessage()
            );

            return 1;
        } finally {
            DB::rollBack();

            config([
                'services.rozetka.api_url' =>
                    $oldApiUrl,

                'services.rozetka.jwt_token' =>
                    $oldToken,

                'services.rozetka.actions_enabled' =>
                    $oldActionsEnabled,
            ]);
        }
    }

    /**
     * Смешанный batch:
     *
     * - новый Rozetka API success;
     * - legacy success;
     * - новый Rozetka API failed.
     *
     * В print-markings должен попасть только первый.
     *
     * @return void
     */
    private function testMixedBatch()
    {
        config([
            'services.rozetka.actions_enabled' =>
                true,
        ]);

        $batch = $this->createBatch();

        $rozetkaOrder = $this->createOrder(
            true,
            'shipping',
            '20400000000101'
        );

        $legacyOrder = $this->createOrder(
            false,
            null,
            '20400000000102'
        );

        $failedRozetkaOrder =
            $this->createOrder(
                true,
                'confirmed',
                null
            );

        $this->createEntry(
            $batch,
            $rozetkaOrder,
            'success',
            '20400000000101'
        );

        $this->createEntry(
            $batch,
            $legacyOrder,
            'success',
            '20400000000102'
        );

        $this->createEntry(
            $batch,
            $failedRozetkaOrder,
            'failed',
            null
        );

        $calls = [];

        $this->resetHttp();

        Http::fake(function (
            HttpRequest $request
        ) use (
            $rozetkaOrder,
            $legacyOrder,
            $failedRozetkaOrder,
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

            if (
                $request->method() !== 'POST' ||
                $path !==
                    '/dropshipping/orders/print-markings'
            ) {
                throw new RuntimeException(
                    'Несподіваний HTTP-запит: ' .
                    $request->method() .
                    ' ' .
                    $path
                );
            }

            $data = $request->data();

            $this->assertSame(
                'nova_poshta',
                $data['deliveryService']
                    ?? null,
                'deliveryService'
            );

            $this->assertSame(
                'pdf',
                $data['format']
                    ?? null,
                'format'
            );

            $this->assertSame(
                '85x85',
                $data['size']
                    ?? null,
                'size'
            );

            $orders =
                $data['orders']
                ?? [];

            $this->assertSame(
                [
                    $rozetkaOrder->guid,
                ],
                $orders,
                'orders payload'
            );

            $this->assertTrue(
                !in_array(
                    $legacyOrder->guid,
                    $orders,
                    true
                ),
                'Legacy-заказ потрапив у print-markings.'
            );

            $this->assertTrue(
                !in_array(
                    $failedRozetkaOrder->guid,
                    $orders,
                    true
                ),
                'Failed Rozetka-заказ потрапив у print-markings.'
            );

            return Http::response(
                "%PDF-1.4\nFAKE ROZETKA MARKING\n",
                200,
                [
                    'Content-Type' =>
                        'application/pdf',

                    'Content-Disposition' =>
                        'attachment; filename="markings-1-85x85.pdf"',

                    'X-Markings-Printed' =>
                        '1',

                    'X-Markings-Skipped' =>
                        '0',
                ]
            );
        });

        $service =
            app(RozetkaMarkingService::class);

        $result =
            $service->printBatchPdf(
                $batch,
                '85x85'
            );

        $this->assertSame(
            1,
            $this->countCalls(
                $calls,
                'POST',
                '/dropshipping/orders/print-markings'
            ),
            'POST print-markings count'
        );

        $this->assertSame(
            "%PDF-1.4\nFAKE ROZETKA MARKING\n",
            $result['body'],
            'PDF body'
        );

        $this->assertTrue(
            strpos(
                strtolower(
                    $result['content_type']
                ),
                'application/pdf'
            ) === 0,
            'content_type не application/pdf.'
        );

        $this->assertSame(
            1,
            $result['printed'],
            'printed'
        );

        $this->assertSame(
            0,
            $result['skipped'],
            'skipped'
        );

        $this->assertSame(
            1,
            $result['requested'],
            'requested'
        );

        $this->assertSame(
            [
                $rozetkaOrder->guid,
            ],
            $result['order_ids'],
            'returned order_ids'
        );

        $this->line(
            'OK: legacy та failed-закази виключені.'
        );

        $this->line(
            'OK: PDF отримано, printed=1, skipped=0.'
        );
    }

    /**
     * При выключенных actions никакого POST быть не должно.
     *
     * @return void
     */
    private function testActionsDisabled()
    {
        $batch =
            $this->createBatch();

        $order =
            $this->createOrder(
                true,
                'shipping',
                '20400000000103'
            );

        $this->createEntry(
            $batch,
            $order,
            'success',
            '20400000000103'
        );

        config([
            'services.rozetka.actions_enabled' =>
                false,
        ]);

        $calls = [];

        $this->resetHttp();

        Http::fake(function (
            HttpRequest $request
        ) use (&$calls) {
            $calls[] = [
                'method' =>
                    $request->method(),

                'url' =>
                    $request->url(),
            ];

            throw new RuntimeException(
                'HTTP не повинен виконуватись ' .
                'при ROZETKA_ACTIONS_ENABLED=false.'
            );
        });

        $service =
            app(RozetkaMarkingService::class);

        $blocked = false;

        try {
            $service->printBatchPdf(
                $batch
            );
        } catch (RuntimeException $e) {
            $blocked =
                strpos(
                    $e->getMessage(),
                    'Дії через Rozetka API вимкнені'
                ) !== false;
        }

        $this->assertTrue(
            $blocked,
            'print-markings не було заблоковано.'
        );

        $this->assertSame(
            0,
            count($calls),
            'HTTP calls при actions=false'
        );

        config([
            'services.rozetka.actions_enabled' =>
                true,
        ]);

        $this->line(
            'OK: POST заблоковано до HTTP-рівня.'
        );
    }

    /**
     * Проверяем исправленный error path requestFile().
     *
     * @return void
     */
    private function testHttp400()
    {
        config([
            'services.rozetka.actions_enabled' =>
                true,
        ]);

        $batch =
            $this->createBatch();

        $order =
            $this->createOrder(
                true,
                'shipping',
                '20400000000104'
            );

        $this->createEntry(
            $batch,
            $order,
            'success',
            '20400000000104'
        );

        $this->resetHttp();

        Http::fake(function (
            HttpRequest $request
        ) {
            $path = (string) parse_url(
                $request->url(),
                PHP_URL_PATH
            );

            if (
                $request->method() === 'POST' &&
                $path ===
                    '/dropshipping/orders/print-markings'
            ) {
                return Http::response([
                    'name' =>
                        'Bad Request',

                    'message' =>
                        'Selected orders have no Nova Poshta tracking numbers',

                    'code' =>
                        0,

                    'status' =>
                        400,
                ], 400);
            }

            throw new RuntimeException(
                'Несподіваний HTTP-запит: ' .
                $request->method() .
                ' ' .
                $path
            );
        });

        $service =
            app(RozetkaMarkingService::class);

        $caught = null;

        try {
            $service->printBatchPdf(
                $batch
            );
        } catch (RozetkaApiException $e) {
            $caught = $e;
        }

        $this->assertTrue(
            $caught instanceof
                RozetkaApiException,
            'HTTP 400 не перетворено на RozetkaApiException.'
        );

        $this->assertSame(
            400,
            $caught->getHttpStatus(),
            'HTTP status'
        );

        $this->assertTrue(
            strpos(
                $caught->getMessage(),
                'Selected orders have no Nova Poshta tracking numbers'
            ) !== false,
            'Повідомлення API втрачено.'
        );

        $this->line(
            'OK: HTTP 400 оброблено без Undefined variable.'
        );
    }

    /**
     * HTTP 200 может содержать неполный набор маркировок.
     *
     * Такой PDF нельзя молча отдавать пользователю.
     *
     * @return void
     */
    private function testPartialMarkings()
    {
        config([
            'services.rozetka.actions_enabled' =>
                true,
        ]);

        $batch =
            $this->createBatch();

        $order1 =
            $this->createOrder(
                true,
                'shipping',
                '20400000000105'
            );

        $order2 =
            $this->createOrder(
                true,
                'shipping',
                '20400000000106'
            );

        $this->createEntry(
            $batch,
            $order1,
            'success',
            '20400000000105'
        );

        $this->createEntry(
            $batch,
            $order2,
            'success',
            '20400000000106'
        );

        $this->resetHttp();

        Http::fake(function (
            HttpRequest $request
        ) use (
            $order1,
            $order2
        ) {
            $path = (string) parse_url(
                $request->url(),
                PHP_URL_PATH
            );

            if (
                $request->method() !== 'POST' ||
                $path !==
                    '/dropshipping/orders/print-markings'
            ) {
                throw new RuntimeException(
                    'Несподіваний HTTP-запит: ' .
                    $request->method() .
                    ' ' .
                    $path
                );
            }

            $data =
                $request->data();

            $this->assertSame(
                [
                    $order1->guid,
                    $order2->guid,
                ],
                $data['orders']
                    ?? [],
                'partial orders payload'
            );

            /*
             * Один заказ напечатан,
             * второй Rozetka пропустила.
             */
            return Http::response(
                "%PDF-1.4\nPARTIAL MARKINGS\n",
                200,
                [
                    'Content-Type' =>
                        'application/pdf',

                    'Content-Disposition' =>
                        'attachment; filename="markings-1-85x85.pdf"',

                    'X-Markings-Printed' =>
                        '1',

                    'X-Markings-Skipped' =>
                        '1',
                ]
            );
        });

        $service =
            app(RozetkaMarkingService::class);

        $caught = null;

        try {
            $service->printBatchPdf(
                $batch
            );
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertTrue(
            $caught instanceof RuntimeException,
            'Неповний PDF не було заблоковано.'
        );

        $this->assertTrue(
            strpos(
                $caught->getMessage(),
                'неповний комплект маркувань'
            ) !== false,
            'Немає зрозумілого повідомлення про неповний комплект.'
        );

        $this->assertTrue(
            strpos(
                $caught->getMessage(),
                'пропущено: 1'
            ) !== false,
            'У повідомленні відсутня кількість пропущених.'
        );

        $this->line(
            'OK: неповний PDF заблоковано, skipped=1.'
        );
    }

    /**
     * Rozetka принимает максимум 100 identifiers.
     *
     * Пакет из 101 подходящего заказа должен
     * блокироваться до выполнения HTTP-запроса.
     *
     * @return void
     */
    private function testMoreThan100Orders()
    {
        config([
            'services.rozetka.actions_enabled' =>
                true,
        ]);

        $batch =
            $this->createBatch();

        for ($i = 1; $i <= 101; $i++) {
            $trackingNumber =
                '20400001' .
                str_pad(
                    (string) $i,
                    6,
                    '0',
                    STR_PAD_LEFT
                );

            $order =
                $this->createOrder(
                    true,
                    'shipping',
                    $trackingNumber
                );

            $this->createEntry(
                $batch,
                $order,
                'success',
                $trackingNumber
            );
        }

        $calls = [];

        $this->resetHttp();

        Http::fake(function (
            HttpRequest $request
        ) use (&$calls) {
            $calls[] = [
                'method' =>
                    $request->method(),

                'url' =>
                    $request->url(),
            ];

            throw new RuntimeException(
                'HTTP не повинен виконуватись ' .
                'для пакета більше 100 замовлень.'
            );
        });

        $service =
            app(RozetkaMarkingService::class);

        $caught = null;

        try {
            $service->printBatchPdf(
                $batch
            );
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertTrue(
            $caught instanceof RuntimeException,
            'Пакет із 101 замовлення не було заблоковано.'
        );

        $this->assertTrue(
            strpos(
                $caught->getMessage(),
                'максимум 100'
            ) !== false,
            'Немає зрозумілого повідомлення про ліміт 100.'
        );

        $this->assertSame(
            0,
            count($calls),
            'HTTP calls для 101 замовлення'
        );

        $this->line(
            'OK: 101 замовлення заблоковано до HTTP-запиту.'
        );
    }

    /**
     * @return TtnBatch
     */
    private function createBatch()
    {
        return TtnBatch::create([
            'status' =>
                'completed',

            'total_count' =>
                0,

            'success_count' =>
                0,

            'failed_count' =>
                0,

            'created_by' =>
                'local-test',

            'started_at' =>
                now(),

            'completed_at' =>
                now(),
        ]);
    }

    /**
     * @param bool $isRozetka
     * @param string|null $rozetkaStatus
     * @param string|null $trackingNumber
     * @return Order
     */
    private function createOrder(
        $isRozetka,
        $rozetkaStatus,
        $trackingNumber
    ) {
        $order =
            new Order();

        $guid =
            (string) Str::uuid();

        /*
         * forceFill используем специально,
         * чтобы тест не зависел от текущего
         * списка $fillable модели Order.
         */
        $order->forceFill([
            'guid' =>
                $guid,

            'order_num' =>
                $isRozetka
                    ? 'РОЗ-TEST-' .
                        substr(
                            str_replace(
                                '-',
                                '',
                                $guid
                            ),
                            0,
                            8
                        )
                    : null,

            'comment' =>
                'MARKINGS-TEST',

            'status' =>
                $trackingNumber
                    ? 'shipped'
                    : 'created',

            'rozetka_status' =>
                $rozetkaStatus,

            'tracking_number' =>
                $trackingNumber,

            'rozetka_synced_at' =>
                $isRozetka
                    ? now()
                    : null,
        ]);

        $order->save();

        return $order;
    }

    /**
     * @param TtnBatch $batch
     * @param Order $order
     * @param string $status
     * @param string|null $trackingNumber
     * @return TtnBatchOrder
     */
    private function createEntry(
        TtnBatch $batch,
        Order $order,
        $status,
        $trackingNumber
    ) {
        return TtnBatchOrder::create([
            'ttn_batch_id' =>
                $batch->id,

            'order_id' =>
                $order->id,

            'status' =>
                $status,

            'tracking_number' =>
                $trackingNumber,

            'attempts' =>
                1,

            'error_message' =>
                null,

            'processed_at' =>
                now(),

            'last_attempt_at' =>
                now(),
        ]);
    }

    /**
     * Сбросить Http::fake между сценариями.
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
                isset(
                    $call['method'],
                    $call['path']
                ) &&
                $call['method'] === $method &&
                $call['path'] === $path
            ) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param mixed $expected
     * @param mixed $actual
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