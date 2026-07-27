<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TtnBatch;
use App\Models\TtnBatchOrder;
use App\Services\NovaPoshta\NovaPoshtaTtnService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class TtnAddressController extends Controller
{
    public function show(
        Request $request,
        TtnBatch $batch,
        TtnBatchOrder $entry,
        NovaPoshtaTtnService $ttnService
    ) {
        $this->ensureEntryBelongsToBatch(
            $batch,
            $entry
        );

        $entry->load('order');

        if (!$entry->order) {
            abort(404, 'Замовлення не знайдено.');
        }

        $order = $entry->order;

        if (trim((string) $order->tracking_number) !== '') {
            return redirect()
                ->route(
                    'admin.ttn-batches.show',
                    $batch
                )
                ->with(
                    'error',
                    'Замовлення вже має створену ТТН.'
                );
        }

        $parsedAddress = null;
        $settlementOptions = [];
        $streetOptions = [];
        $searchError = null;

        /*
         * Название, которое будет отправлено
         * в поиск улиц Новой Почты.
         */
        $streetSearch = trim(
            (string) $request->query(
                'street_search',
                ''
            )
        );

        try {
            $parsedAddress =
                $ttnService->parseAddressForManualChoice(
                    $order
                );

            /*
             * По умолчанию используем улицу из заказа.
             */
            if ($streetSearch === '') {
                $streetSearch = trim(
                    (string) $parsedAddress['street']
                );
            }

            $settlementOptions =
                $ttnService->searchSettlementOptions(
                    $order->delivery_city
                );

            if (
                $this->isUuid(
                    $order->np_recipient_settlement_ref
                )
                && $this->isUuid(
                    $order->np_city_ref
                )
            ) {
                $streetOptions =
                    $ttnService->searchStreetOptions(
                        $order->np_city_ref,
                        $order->np_recipient_settlement_ref,
                        $streetSearch
                    );
            }
        } catch (Throwable $e) {
            $searchError = $e->getMessage();
        }

        return view(
            'admin.ttn-batches.address',
            compact(
                'batch',
                'entry',
                'order',
                'parsedAddress',
                'settlementOptions',
                'streetOptions',
                'streetSearch',
                'searchError'
            )
        );
    }

    public function storeSettlement(
        Request $request,
        TtnBatch $batch,
        TtnBatchOrder $entry,
        NovaPoshtaTtnService $ttnService
    ) {
        $this->ensureEntryBelongsToBatch(
            $batch,
            $entry
        );

        $entry->load('order');

        if (!$entry->order) {
            abort(404, 'Замовлення не знайдено.');
        }

        $data = $request->validate([
            'settlement_ref' => [
                'required',
                'uuid',
            ],
        ], [
            'settlement_ref.required' =>
                'Оберіть населений пункт.',
        ]);

        try {
            /*
             * Повторно проверяем выбор через API.
             * Данным из HTML напрямую не доверяем.
             */
            $selected =
                $ttnService->selectSettlementOption(
                    $entry->order->delivery_city,
                    $data['settlement_ref']
                );

            DB::transaction(function () use (
                $entry,
                $selected
            ) {
                $entry->order->forceFill([
                    'np_recipient_settlement_ref' =>
                        $selected['settlement_ref'],

                    'np_city_ref' =>
                        $selected['city_ref'],

                    'np_recipient_settlement_name' =>
                        $this->makeSettlementLabel(
                            $selected
                        ),

                    /*
                     * После изменения города
                     * старый выбор улицы недействителен.
                     */
                    'np_recipient_street_ref' => null,
                    'np_recipient_street_name' => null,
                ])->save();

                $entry->forceFill([
                    'status' => 'failed',

                    'error_message' =>
                        'Населений пункт уточнено. ' .
                        'Оберіть вулицю.',

                    'processed_at' => now(),
                ])->save();
            });

            return redirect()
                ->route(
                    'admin.ttn-batches.address.show',
                    [$batch, $entry]
                )
                ->with(
                    'success',
                    'Населений пункт збережено. ' .
                    'Тепер оберіть вулицю.'
                );
        } catch (Throwable $e) {
            report($e);

            return back()
                ->withInput()
                ->with(
                    'error',
                    $e->getMessage()
                );
        }
    }

    public function storeStreet(
        Request $request,
        TtnBatch $batch,
        TtnBatchOrder $entry,
        NovaPoshtaTtnService $ttnService
    ) {
        $this->ensureEntryBelongsToBatch(
            $batch,
            $entry
        );

        $entry->load('order');

        if (!$entry->order) {
            abort(404, 'Замовлення не знайдено.');
        }

        $order = $entry->order;

        if (
            !$this->isUuid(
                $order->np_recipient_settlement_ref
            )
            || !$this->isUuid($order->np_city_ref)
        ) {
            return back()->with(
                'error',
                'Спочатку оберіть населений пункт.'
            );
        }

        $data = $request->validate([
            'street_ref' => [
                'required',
                'uuid',
            ],

            'street_search' => [
                'required',
                'string',
                'max:255',
            ],
        ], [
            'street_ref.required' =>
                'Оберіть вулицю.',

            'street_search.required' =>
                'Вкажіть текст для пошуку вулиці.',
        ]);

        try {
            $parsedAddress =
                $ttnService->parseAddressForManualChoice(
                    $order
                );

            $selected =
                $ttnService->selectStreetOption(
                $order->np_city_ref,
                $order->np_recipient_settlement_ref,
                trim($data['street_search']),
                $data['street_ref']
            );

            DB::transaction(function () use (
                $batch,
                $entry,
                $order,
                $selected
            ) {
                $order->forceFill([
                    'np_recipient_street_ref' =>
                        $selected['street_ref'],

                    'np_recipient_street_name' =>
                        $selected['present']
                            ?: $selected['name'],

                    'np_recipient_street_source' =>
                        $selected['source'],
                ])->save();

                /*
                 * Заказ готов к повторной обработке.
                 */
                $entry->forceFill([
                    'status' => 'pending',
                    'tracking_number' => null,
                    'error_message' => null,
                    'processed_at' => null,
                    'last_attempt_at' => null,
                ])->save();

                $this->refreshBatchState($batch);
            });

            return redirect()
                ->route(
                    'admin.ttn-batches.show',
                    $batch
                )
                ->with(
                    'success',
                    'Адресу уточнено. Замовлення №' .
                    $order->id .
                    ' повернуто до обробки.'
                );
        } catch (Throwable $e) {
            report($e);

            return back()
                ->withInput()
                ->with(
                    'error',
                    $e->getMessage()
                );
        }
    }

    public function reset(
        TtnBatch $batch,
        TtnBatchOrder $entry
    ) {
        $this->ensureEntryBelongsToBatch(
            $batch,
            $entry
        );

        $entry->load('order');

        if (!$entry->order) {
            abort(404, 'Замовлення не знайдено.');
        }

        DB::transaction(function () use ($entry) {
            $entry->order->forceFill([
                'np_recipient_settlement_ref' => null,
                'np_recipient_settlement_name' => null,

                'np_recipient_street_ref' => null,
                'np_recipient_street_name' => null,

                'np_city_ref' => null,
                'np_recipient_street_source' => null,
            ])->save();

            $entry->forceFill([
                'status' => 'failed',

                'error_message' =>
                    'Ручне уточнення адреси скинуто.',

                'processed_at' => now(),
            ])->save();
        });

        return redirect()
            ->route(
                'admin.ttn-batches.address.show',
                [$batch, $entry]
            )
            ->with(
                'success',
                'Збережений вибір адреси скинуто.'
            );
    }

    private function ensureEntryBelongsToBatch(
        TtnBatch $batch,
        TtnBatchOrder $entry
    ) {
        if (
            (int) $entry->ttn_batch_id
            !== (int) $batch->id
        ) {
            abort(404);
        }
    }

    private function makeSettlementLabel(array $selected)
    {
        $parts = [
            $selected['name'] ?? '',
            $selected['area'] ?? '',
            $selected['region'] ?? '',
        ];

        return implode(
            ', ',
            array_filter($parts)
        );
    }

    private function refreshBatchState(TtnBatch $batch)
    {
        $counts = TtnBatchOrder::query()
            ->where('ttn_batch_id', $batch->id)

            ->selectRaw(
                "SUM(CASE WHEN status = 'success' " .
                "THEN 1 ELSE 0 END) AS success_count"
            )

            ->selectRaw(
                "SUM(CASE WHEN status = 'failed' " .
                "THEN 1 ELSE 0 END) AS failed_count"
            )

            ->selectRaw(
                "SUM(CASE WHEN status IN " .
                "('pending', 'processing') " .
                "THEN 1 ELSE 0 END) AS pending_count"
            )

            ->first();

        $successCount =
            (int) $counts->success_count;

        $failedCount =
            (int) $counts->failed_count;

        $pendingCount =
            (int) $counts->pending_count;

        if ($pendingCount > 0) {
            $status = 'pending';
            $completedAt = null;
        } elseif (
            $successCount > 0
            && $failedCount === 0
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

        $batch->forceFill([
            'status' => $status,
            'success_count' => $successCount,
            'failed_count' => $failedCount,
            'completed_at' => $completedAt,
        ])->save();
    }

    private function isUuid($value)
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-' .
            '[0-9a-f]{4}-[0-9a-f]{4}-' .
            '[0-9a-f]{12}$/i',
            trim((string) $value)
        ) === 1;
    }
}