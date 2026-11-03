<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\ProductDimension;
use App\Models\RozetkaAutoTtnAttempt;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class TestRozetkaAutoTtnFlow extends Command
{
    protected $signature = 'rozetka:test-auto-ttn-flow';

    protected $description =
        'Fake HTTP regression test of opt-in TTN creation, duplicate guard and dry-run';

    public function handle()
    {
        $previous = [
            'services.rozetka.api_url' => config('services.rozetka.api_url'),
            'services.rozetka.jwt_token' => config('services.rozetka.jwt_token'),
            'services.rozetka.actions_enabled' => config('services.rozetka.actions_enabled'),
            'services.rozetka.auto_ttn_enabled' => config('services.rozetka.auto_ttn_enabled'),
            'services.rozetka.auto_ttn_only_order_id' => config('services.rozetka.auto_ttn_only_order_id'),
        ];

        config([
            'services.rozetka.api_url' => 'https://fake-rozetka-auto-ttn.test',
            'services.rozetka.jwt_token' => 'fake-token',
            'services.rozetka.actions_enabled' => true,
            'services.rozetka.auto_ttn_enabled' => true,
            'services.rozetka.auto_ttn_only_order_id' => 0,
        ]);

        DB::beginTransaction();

        try {
            $this->testSchedulerUsesBareExecuteFlag();
            $this->testDryRunAndSingleCreation();
            $this->testIncompleteQuantityExcluded();
            $this->testUnknownOutcomeNotRetried();

            $this->info(
                'OK: dry-run, one real fake POST, partial quantity guard, ' .
                'unknown result held for review. All changes rolled back.'
            );
            return 0;
        } catch (Throwable $e) {
            $this->error(get_class($e) . ': ' . $e->getMessage());
            return 1;
        } finally {
            DB::rollBack();
            config($previous);
        }
    }

    /**
     * Regression: Laravel Scheduler must not pass --execute=1.
     */
    private function testSchedulerUsesBareExecuteFlag()
    {
        config(['services.rozetka.auto_ttn_only_order_id' => 549]);

        $kernel = app(\\App\\Console\\Kernel::class);
        $method = new \\ReflectionMethod($kernel, 'schedule');
        $method->setAccessible(true);
        $schedule = new \\Illuminate\\Console\\Scheduling\\Schedule();
        $method->invoke($kernel, $schedule);

        $matches = array_values(array_filter(
            $schedule->events(),
            function ($event) {
                return strpos($event->command, 'rozetka:auto-ttn') !== false;
            }
        ));

        $this->assertTrue(
            count($matches) === 1 &&
            preg_match('/(?:^|\\s)--execute(?:\\s|$)/', $matches[0]->command) === 1 &&
            strpos($matches[0]->command, '--execute=') === false &&
            strpos($matches[0]->command, '--order-id=549') !== false,
            'Scheduled command must use a bare --execute flag and canary ID'
        );

        config(['services.rozetka.auto_ttn_only_order_id' => 0]);
    }

    private function makeOrder($suffix, $confirmedQuantity)
    {
        $rzCode = (string) (800000000 + random_int(1000000, 9999999));

        ProductDimension::create([
            'rz_code' => $rzCode,
            'name' => 'FAKE TTN test product',
            'weight' => 10,
            'length' => 100,
            'width' => 50,
            'height' => 8,
        ]);

        $order = Order::create([
            'guid' => (string) Str::uuid(),
            'order_num' => 'LOCAL-AUTO-' . $suffix . '-' . Str::random(6),
            'status' => 'created',
            'rozetka_status' => 'confirmed',
            'uzd_status' => 'verified',
            'delivery_type' => 'DropShipping',
            'delivery_company' => 'Nova Poshta',
            'sn_required' => false,
            'rozetka_synced_at' => now(),
        ]);

        $item = $order->items()->create([
            'supplier_code' => 'TEST-' . $suffix,
            'rz_code' => $rzCode,
            'quantity' => 1,
            'reserved_quantity' => 1,
            'confirmed_quantity' => $confirmedQuantity,
            'price' => null,
        ]);

        return [$order, $item];
    }

    private function fakeGetResponse(Order $order, $ttn = null)
    {
        $item = $order->items->first();

        return [
            'orderId' => $order->guid,
            'orderNum' => $order->order_num,
            'orderStatus' => $ttn ? 'shipping' : 'confirmed',
            'uzdStatus' => 'verified',
            'ttn' => $ttn,
            'snRequired' => false,
            'products' => [[
                'supplierCode' => $item->supplier_code,
                'rzCode' => (int) $item->rz_code,
                'quantity' => 1,
                'confirmedQuantity' => 1,
                'serialNumbers' => [],
            ]],
        ];
    }

    private function testDryRunAndSingleCreation()
    {
        list($order) = $this->makeOrder('SUCCESS', 1);
        $calls = [];

        Http::swap(new HttpFactory());
        Http::fake(function (HttpRequest $request) use ($order, &$calls) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $calls[] = [$request->method(), $path];

            if ($request->method() === 'GET' &&
                $path === '/dropshipping/orders/' . $order->guid
            ) {
                return Http::response($this->fakeGetResponse($order), 200);
            }

            if ($request->method() === 'POST' &&
                $path === '/dropshipping/orders/ttn/' . $order->guid
            ) {
                $data = $request->data();
                $this->assertTrue(
                    !empty($data['cargoSeats']) &&
                    count($data['cargoSeats']) === 1,
                    'Missing cargo seats in TTN POST'
                );

                return Http::response([
                    'orderId' => $order->guid,
                    'orderStatus' => 'shipping',
                    'ttn' => '20400000001001',
                    'shippingPoint' => 'Fake point',
                ], 200);
            }

            throw new RuntimeException('Unexpected HTTP call: ' . $path);
        });

        $exit = Artisan::call('rozetka:auto-ttn', [
            '--order-id' => (string) $order->id,
        ]);

        $this->assertTrue(
            $exit === 0 &&
            count($calls) === 0 &&
            RozetkaAutoTtnAttempt::where('order_id', $order->id)->count() === 0,
            'Dry-run should not perform HTTP or write an attempt'
        );

        // Simulate the scheduler: no --order-id is passed to the
        // command itself, but the configured canary must scope it.
        config(['services.rozetka.auto_ttn_only_order_id' => $order->id]);

        $wrongIdExit = Artisan::call('rozetka:auto-ttn', [
            '--execute' => true,
            '--order-id' => (string) ($order->id + 1),
        ]);

        $this->assertTrue(
            $wrongIdExit === 1 &&
            count($calls) === 0,
            'Canary must block execution for any other order'
        );

        $exit = Artisan::call('rozetka:auto-ttn', [
            '--execute' => true,
        ]);

        $attempt = RozetkaAutoTtnAttempt::where('order_id', $order->id)->first();

        $this->assertTrue(
            $exit === 0 &&
            $attempt &&
            $attempt->status === 'success' &&
            $attempt->tracking_number === '20400000001001' &&
            count(array_filter($calls, function ($entry) {
                return $entry[0] === 'POST';
            })) === 1,
            'Expected exactly one successful TTN POST'
        );

        Artisan::call('rozetka:auto-ttn', [
            '--execute' => true,
        ]);

        $this->assertTrue(
            count(array_filter($calls, function ($entry) {
                return $entry[0] === 'POST';
            })) === 1,
            'Second run must not send another TTN POST'
        );

        config(['services.rozetka.auto_ttn_only_order_id' => 0]);
    }

    private function testIncompleteQuantityExcluded()
    {
        list($order) = $this->makeOrder('PARTIAL', 0);

        Http::swap(new HttpFactory());
        Http::fake(function () {
            throw new RuntimeException('Partial order should not call API');
        });

        Artisan::call('rozetka:auto-ttn', [
            '--execute' => true,
            '--order-id' => (string) $order->id,
        ]);

        $this->assertTrue(
            RozetkaAutoTtnAttempt::where('order_id', $order->id)->count() === 0,
            'Incomplete quantity should be excluded without creating an attempt'
        );
    }

    private function testUnknownOutcomeNotRetried()
    {
        list($order) = $this->makeOrder('UNCERTAIN', 1);
        $calls = [];

        Http::swap(new HttpFactory());
        Http::fake(function (HttpRequest $request) use ($order, &$calls) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $calls[] = [$request->method(), $path];

            if ($request->method() === 'GET' &&
                $path === '/dropshipping/orders/' . $order->guid
            ) {
                return Http::response($this->fakeGetResponse($order), 200);
            }

            if ($request->method() === 'POST' &&
                $path === '/dropshipping/orders/ttn/' . $order->guid
            ) {
                return Http::response([
                    'message' => 'Unknown delivery outcome',
                ], 500);
            }

            throw new RuntimeException('Unexpected HTTP call: ' . $path);
        });

        Artisan::call('rozetka:auto-ttn', [
            '--execute' => true,
            '--order-id' => (string) $order->id,
        ]);

        $attempt = RozetkaAutoTtnAttempt::where('order_id', $order->id)->first();

        $this->assertTrue(
            $attempt && $attempt->status === 'needs_review',
            'Unknown result must require manual review'
        );

        Artisan::call('rozetka:auto-ttn', [
            '--execute' => true,
            '--order-id' => (string) $order->id,
        ]);

        $posts = array_filter($calls, function ($call) {
            return $call[0] === 'POST';
        });

        $this->assertTrue(
            count($posts) === 1,
            'Unknown outcome must block any automatic retry'
        );
    }

    private function assertTrue($condition, $message)
    {
        if (!$condition) {
            throw new RuntimeException('TEST FAILED: ' . $message);
        }
    }
}
