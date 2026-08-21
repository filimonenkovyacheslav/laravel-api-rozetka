<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\TtnBatch;
use App\Models\TtnBatchOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\NovaPoshta\NovaPoshtaTtnService;
use Throwable;

class TtnBatchController extends Controller
{
    /**
     * Список пакетов массового формирования ТТН.
     */
    public function index(Request $request)
    {
        $batches = TtnBatch::query()
            ->withCount('entries')
            ->orderByDesc('id')
            ->paginate(30)
            ->appends($request->query());

        return view(
            'admin.ttn-batches.index',
            compact('batches')
        );
    }

    /**
     * Создать пакет из выбранных заказов.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'order_ids' => [
                'required',
                'array',
                'min:1',
            ],

            'order_ids.*' => [
                'required',
                'integer',
                'distinct',
                'exists:orders,id',
            ],
        ], [
            'order_ids.required' =>
                'Оберіть хоча б одне замовлення.',

            'order_ids.min' =>
                'Оберіть хоча б одне замовлення.',

            'order_ids.*.exists' =>
                'Одне з вибраних замовлень не знайдено.',
        ]);

        $selectedIds = collect($validated['order_ids'])
            ->map(function ($id) {
                return (int) $id;
            })
            ->filter()
            ->unique()
            ->values();

        /*
         * В пакет включаем только заказы:
         *
         * 1. которые существуют;
         * 2. не отменены;
         * 3. ещё не имеют ТТН.
         */
        $orders = Order::query()
            ->whereIn('id', $selectedIds)
            ->where('status', '<>', 'canceled')
            ->where(function ($query) {
                $query
                    ->whereNull('tracking_number')
                    ->orWhere('tracking_number', '');
            })
            ->orderBy('id')
            ->get();

        if ($orders->isEmpty()) {
            return back()->with(
                'error',
                'Серед вибраних замовлень немає доступних для формування ТТН. ' .
                'Можливо, вони вже мають ТТН або були скасовані.'
            );
        }

        $createdBy = $this->resolveCreatedBy($request);

        $batch = DB::transaction(function () use (
            $orders,
            $createdBy
        ) {
            $batch = TtnBatch::create([
                'status' => 'pending',
                'total_count' => $orders->count(),
                'success_count' => 0,
                'failed_count' => 0,
                'created_by' => $createdBy,
                'started_at' => null,
                'completed_at' => null,
            ]);

            $now = now();

            $rows = $orders
                ->map(function ($order) use ($batch, $now) {
                    return [
                        'ttn_batch_id' => $batch->id,
                        'order_id' => $order->id,

                        'status' => 'pending',
                        'tracking_number' => null,

                        'attempts' => 0,
                        'error_message' => null,

                        'processed_at' => null,
                        'last_attempt_at' => null,

                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                })
                ->all();

            TtnBatchOrder::insert($rows);

            return $batch;
        });

        $skippedCount = $selectedIds->count() - $orders->count();

        $message = sprintf(
            'Пакет №%d створено. Додано замовлень: %d.',
            $batch->id,
            $orders->count()
        );

        if ($skippedCount > 0) {
            $message .= sprintf(
                ' Пропущено: %d — вже мають ТТН або скасовані.',
                $skippedCount
            );
        }

        return redirect()
            ->route('admin.ttn-batches.show', $batch)
            ->with('success', $message);
    }

    /**
     * Страница конкретного пакета.
     */
    public function show(TtnBatch $batch)
    {
        $batch->load([
            'entries' => function ($query) {
                $query
                    ->with('order')
                    ->orderBy('id');
            },

            'exports' => function ($query) {
                $query->orderByDesc('id');
            },
        ]);

        return view(
            'admin.ttn-batches.show',
            compact('batch')
        );
    }

    /**
     * Имя пользователя, создавшего пакет.
     */
    private function resolveCreatedBy(Request $request)
    {
        if (auth()->check()) {
            return auth()->user()->email
                ?? auth()->user()->name
                ?? null;
        }

        /*
         * Для текущей HTTP Basic авторизации.
         */
        return $request->getUser();
    }

    /**
     * Генерация ТТН.
     */
    public function processNext(
        TtnBatch $batch,
        NovaPoshtaTtnService $ttnService
    ) {
        /*
         * Атомарно резервируем следующую запись,
         * чтобы один заказ не обрабатывался дважды.
         */
        $entryId = DB::transaction(function () use ($batch) {
            $entry = TtnBatchOrder::query()
                ->where('ttn_batch_id', $batch->id)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->orderBy('id')
                ->first();

            if (!$entry) {
                return null;
            }

            $entry->update([
                'status' => 'processing',

                'attempts' =>
                    (int) $entry->attempts + 1,

                'last_attempt_at' => now(),

                'error_message' => null,
            ]);

            $batchForUpdate = TtnBatch::query()
                ->whereKey($batch->id)
                ->lockForUpdate()
                ->first();

            $batchForUpdate->update([
                'status' => 'processing',

                'started_at' =>
                    $batchForUpdate->started_at ?: now(),

                'completed_at' => null,
            ]);

            return $entry->id;
        });

        /*
         * Pending-записей больше нет.
         */
        if (!$entryId) {
            $this->refreshBatchState($batch);

            return response()->json([
                'done' => true,
                'batch' => $batch->fresh(),
            ]);
        }

        $entry = TtnBatchOrder::query()
            ->with('order')
            ->findOrFail($entryId);

        try {
            if (!$entry->order) {
                throw new \RuntimeException(
                    'Замовлення було видалено.'
                );
            }

            $result = $ttnService->createForOrder(
                $entry->order
            );

            $entry->update([
                'status' => 'success',

                'tracking_number' =>
                    $result['tracking_number'],

                'processed_at' => now(),

                'error_message' => null,
            ]);

            $success = true;
            $message =
                'ТТН ' .
                $result['tracking_number'] .
                ' успішно створено.';
        } catch (Throwable $e) {
            $entry->update([
                'status' => 'failed',

                'tracking_number' => null,

                'processed_at' => now(),

                'error_message' => mb_substr(
                    $e->getMessage(),
                    0,
                    65000
                ),
            ]);

            report($e);

            $success = false;
            $message = $e->getMessage();
        }

        $batchState = $this->refreshBatchState($batch);

        return response()->json([
            'done' => $batchState['pending_count'] === 0,

            'success' => $success,
            'message' => $message,

            'entry' => [
                'id' => $entry->id,
                'order_id' => $entry->order_id,
                'status' => $entry->fresh()->status,
                'tracking_number' =>
                    $entry->fresh()->tracking_number,
            ],

            'batch' => $batch->fresh(),
        ]);
    }

    private function refreshBatchState(TtnBatch $batch)
    {
        $counts = TtnBatchOrder::query()
            ->where('ttn_batch_id', $batch->id)
            ->selectRaw(
                "SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) " .
                "AS success_count"
            )
            ->selectRaw(
                "SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) " .
                "AS failed_count"
            )
            ->selectRaw(
                "SUM(CASE WHEN status IN ('pending', 'processing') " .
                "THEN 1 ELSE 0 END) AS pending_count"
            )
            ->first();

        $successCount = (int) $counts->success_count;
        $failedCount = (int) $counts->failed_count;
        $pendingCount = (int) $counts->pending_count;

        if ($pendingCount > 0) {
            $status = 'processing';
            $completedAt = null;
        } elseif (
            $successCount > 0 &&
            $failedCount === 0
        ) {
            $status = 'completed';
            $completedAt = now();
        } elseif ($successCount > 0) {
            $status = 'partial';
            $completedAt = now();
        } else {
            $status = 'failed';
            $completedAt = now();
        }

        $previousStatus = $batch->status;
        $batch->update([
            'status' => $status,
            'success_count' => $successCount,
            'failed_count' => $failedCount,
            'completed_at' => $completedAt,
        ]);
        /*
         * Автоматический Excel только в момент перехода
         *
         * ... → completed
         *
         * Повторное сохранение уже completed-пакета
         * письмо не отправляет.
         */
        if (
            $previousStatus !== 'completed'
            && $status === 'completed'
        ) {
            try {
                app(
                    \App\Services\OrderReportService::class
                )->generateAndSend(
                    $batch,
                    'auto:batch-completed'
                );
            } catch (\Throwable $e) {
                /*
                 * Ошибка Excel/email НЕ должна превращать
                 * успешно сформированный пакет ТТН в failed.
                 */
                report($e);
            }
        }

        return [
            'success_count' => $successCount,
            'failed_count' => $failedCount,
            'pending_count' => $pendingCount,
            'status' => $status,
        ];
    }

    public function deleteNext(
        TtnBatch $batch,
        NovaPoshtaTtnService $ttnService
    ) {
        /*
         * Берём следующую успешно созданную ТТН.
         */
        $entry = TtnBatchOrder::query()
            ->with('order')
            ->where('ttn_batch_id', $batch->id)
            ->where('status', 'success')
            ->whereNotNull('tracking_number')
            ->orderBy('id')
            ->first();

        /*
         * Созданных ТТН больше нет.
         */
        if (!$entry) {
            return response()->json([
                'done' => true,
                'success' => true,
                'message' =>
                    'Усі створені ТТН уже видалено.',
                'batch' => $batch->fresh(),
            ]);
        }

        try {
            if (!$entry->order) {
                throw new \RuntimeException(
                    'Замовлення №' .
                    $entry->order_id .
                    ' не знайдено.'
                );
            }

            $result = $ttnService->deleteForOrder(
                $entry->order
            );

            $remainingCount = TtnBatchOrder::query()
                ->where('ttn_batch_id', $batch->id)
                ->where('status', 'success')
                ->whereNotNull('tracking_number')
                ->count();

            return response()->json([
                'done' => $remainingCount === 0,
                'success' => true,

                'message' =>
                    'ТТН ' .
                    ($result['tracking_number'] ?: '') .
                    ' видалено.',

                'deleted_order_id' => $entry->order_id,
                'remaining_count' => $remainingCount,

                'batch' => $batch->fresh(),
            ]);
        } catch (Throwable $e) {
            $entry->update([
                'error_message' => mb_substr(
                    'Помилка видалення ТТН: ' .
                    $e->getMessage(),
                    0,
                    65000
                ),
            ]);

            report($e);

            /*
             * Останавливаем массовое удаление,
             * чтобы не зациклиться на одной ошибочной ТТН.
             */
            return response()->json([
                'done' => false,
                'success' => false,

                'message' =>
                    'Не вдалося видалити ТТН для замовлення №' .
                    $entry->order_id .
                    ': ' .
                    $e->getMessage(),

                'batch' => $batch->fresh(),
            ], 422);
        }
    }

    public function retryFailed(TtnBatch $batch)
    {
        $retriedCount = DB::transaction(function () use ($batch) {
            $failedEntries = TtnBatchOrder::query()
                ->where('ttn_batch_id', $batch->id)
                ->where('status', 'failed')
                ->lockForUpdate()
                ->get();

            if ($failedEntries->isEmpty()) {
                return 0;
            }

            TtnBatchOrder::query()
                ->whereIn(
                    'id',
                    $failedEntries->pluck('id')->all()
                )
                ->update([
                    'status' => 'pending',
                    'tracking_number' => null,
                    'error_message' => null,
                    'processed_at' => null,
                    'updated_at' => now(),
                ]);

            $successCount = TtnBatchOrder::query()
                ->where('ttn_batch_id', $batch->id)
                ->where('status', 'success')
                ->count();

            $batch->update([
                'status' => 'pending',
                'success_count' => $successCount,
                'failed_count' => 0,
                'completed_at' => null,
            ]);

            return $failedEntries->count();
        });

        if ($retriedCount === 0) {
            return back()->with(
                'error',
                'У пакеті немає замовлень з помилками.'
            );
        }

        return back()->with(
            'success',
            'Повернуто до обробки замовлень: ' .
            $retriedCount . '.'
        );
    }
}