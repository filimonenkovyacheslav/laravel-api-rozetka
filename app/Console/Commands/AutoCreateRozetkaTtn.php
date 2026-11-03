<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\RozetkaAutoTtnAttempt;
use App\Services\OrderTtnService;
use App\Services\Rozetka\RozetkaFulfillmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class AutoCreateRozetkaTtn extends Command
{
    protected $signature = 'rozetka:auto-ttn
        {--execute : Really create TTNs (requires dedicated auto-TTN flag)}
        {--order-id= : Only inspect/process one local order ID}
        {--limit=20 : Maximum number of orders to inspect}';

    protected $description =
        'Safe automatic TTN creation for confirmed/verified Rozetka orders (dry-run by default)';

    public function handle(
        OrderTtnService $ttnService,
        RozetkaFulfillmentService $fulfillment
    ) {
        $execute = (bool) $this->option('execute');
        $limit = (int) $this->option('limit');
        $id = $this->option('order-id');

        if ($limit < 1 || $limit > 100) {
            $this->error('Option --limit must be between 1 and 100.');
            return 1;
        }

        if ($id !== null && (!ctype_digit((string) $id) || (int) $id < 1)) {
            $this->error('Option --order-id must be a positive local order ID.');
            return 1;
        }

        /*
         * A production canary also constrains direct --execute calls,
         * not just the scheduler's command arguments.
         */
        $canaryOrderId = (int) config(
            'services.rozetka.auto_ttn_only_order_id',
            0
        );

        if ($execute && $canaryOrderId > 0) {
            if ($id !== null && (int) $id !== $canaryOrderId) {
                $this->error(
                    'Canary mode: only order ID ' . $canaryOrderId .
                    ' is allowed for --execute.'
                );
                return 1;
            }

            $id = (string) $canaryOrderId;
        }

        if ($execute && (
            !config('services.rozetka.auto_ttn_enabled', false) ||
            !config('services.rozetka.actions_enabled', false)
        )) {
            $this->error(
                'TTN creation is disabled. Both ROZETKA_AUTO_TTN_ENABLED ' .
                'and ROZETKA_ACTIONS_ENABLED must be true.'
            );
            return 1;
        }

        if (!$execute) {
            $this->warn('DRY RUN: no HTTP writes and no DB writes.');
        }

        $query = Order::query()
            ->with('items')
            ->where('rozetka_status', 'confirmed')
            ->where('uzd_status', 'verified')
            ->where(function ($q) {
                $q->whereNull('tracking_number')
                    ->orWhere('tracking_number', '');
            })
            ->whereHas('items')
            ->whereDoesntHave('items', function ($q) {
                $q->whereNull('confirmed_quantity')
                    ->orWhereColumn('confirmed_quantity', '<>', 'quantity')
                    ->orWhere('quantity', '<', 1);
            })
            ->whereNotIn(
                'id',
                RozetkaAutoTtnAttempt::query()->select('order_id')
            )
            ->whereDoesntHave('ttnBatchEntries');

        if ($id !== null) {
            // Explicit investigation of a known order, regardless of age.
            $query->whereKey((int) $id);
        } else {
            // Avoid unexpectedly shipping stale confirmed orders when
            // this feature is first enabled on an existing installation.
            $lookbackDays = max(
                1,
                (int) config('services.rozetka.auto_ttn_lookback_days', 14)
            );
            $query->where('created_at', '>=', now()->subDays($lookbackDays));
        }

        // Inspect extra candidates: orders without dimensions must
        // not starve newer orders that are ready to ship.
        $orders = $query->orderBy('id')->limit(max($limit * 20, 100))->get();

        $checked = 0;
        $ready = 0;
        $created = 0;
        $needsReview = 0;

        foreach ($orders as $order) {
            if ($ready >= $limit) {
                break;
            }

            $checked++;

            try {
                $this->checkLocalReadiness($order, $fulfillment);
            } catch (Throwable $e) {
                $this->warn(
                    $order->order_num . ': not ready: ' . $e->getMessage()
                );
                continue;
            }

            $ready++;

            if (!$execute) {
                $this->line(
                    $order->order_num . ' (ID ' . $order->id .
                    '): READY; preview only.'
                );
                continue;
            }

            /*
             * Unique order_id prevents a second scheduled process
             * from starting another POST for the same order.
             * A left-over "processing" row after a crash is also a
             * deliberate block until a person reconciles the outcome.
             */
            $inserted = DB::table('rozetka_auto_ttn_attempts')
                ->insertOrIgnore([
                    'order_id' => $order->id,
                    'status' => 'processing',
                    'attempted_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            if (!$inserted) {
                $this->warn($order->order_num . ': already attempted; skipped.');
                continue;
            }

            $attempt = RozetkaAutoTtnAttempt::where('order_id', $order->id)
                ->firstOrFail();

            try {
                // The service first GETs remote state; it never
                // implicitly confirms orders. Its TTN POST is guarded
                // by full quantity / UZD checks and reconciliation.
                $result = $ttnService->createForOrder($order);

                $attempt->update([
                    'status' => 'success',
                    'tracking_number' => $result['tracking_number'] ?? null,
                    'completed_at' => now(),
                    'error_message' => null,
                ]);
                $created++;

                $this->info(
                    $order->order_num . ': TTN ' .
                    ($result['tracking_number'] ?? '?')
                );
            } catch (Throwable $e) {
                /*
                 * We cannot prove whether a network call reached
                 * Rozetka. Do not retry automatically, even on restart.
                 * The operator must verify the actual remote TTN.
                 */
                $attempt->update([
                    'status' => 'needs_review',
                    'error_message' => mb_substr($e->getMessage(), 0, 65000),
                    'completed_at' => now(),
                ]);
                $needsReview++;
                report($e);
                $this->error(
                    $order->order_num . ': requires manual review: ' .
                    $e->getMessage()
                );
            }
        }

        $this->line(sprintf(
            'Checked: %d; locally ready: %d; TTNs created: %d; ' .
            'needs review: %d.',
            $checked, $ready, $created, $needsReview
        ));

        return $needsReview > 0 ? 1 : 0;
    }

    /**
     * No network calls. A remote status check still happens in
     * OrderTtnService immediately before an actual POST.
     */
    private function checkLocalReadiness(
        Order $order,
        RozetkaFulfillmentService $fulfillment
    ) {
        if ($order->rozetka_status !== 'confirmed' ||
            $order->uzd_status !== 'verified' ||
            trim((string) $order->tracking_number) !== ''
        ) {
            throw new RuntimeException('Order is no longer eligible.');
        }

        if ($order->items->isEmpty()) {
            throw new RuntimeException('No order products.');
        }

        foreach ($order->items as $item) {
            $quantity = (int) $item->quantity;

            if ($quantity < 1 ||
                $item->confirmed_quantity === null ||
                (int) $item->confirmed_quantity !== $quantity
            ) {
                throw new RuntimeException(
                    'Incomplete confirmation: ' . $item->supplier_code
                );
            }

            if ($order->sn_required) {
                $raw = trim((string) $item->serial_number);
                $serials = json_decode($raw, true);

                if (!is_array($serials)) {
                    $serials = $raw === '' ? [] : [$raw];
                }

                $serials = array_filter($serials, function ($sn) {
                    return trim((string) $sn) !== '';
                });

                if (count($serials) !== $quantity) {
                    throw new RuntimeException(
                        'Missing serial numbers: ' . $item->supplier_code
                    );
                }
            }
        }

        // Validate local cargo dimensions; do not POST anything.
        $seats = $fulfillment->buildCargoSeats($order);

        if (empty($seats)) {
            throw new RuntimeException('Cargo seats are empty.');
        }
    }
}
