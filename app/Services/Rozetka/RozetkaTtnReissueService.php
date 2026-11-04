<?php

namespace App\Services\Rozetka;

use App\Models\Order;
use App\Models\RozetkaTtnReissueAttempt;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class RozetkaTtnReissueService
{
    private $fulfillment;

    public function __construct(RozetkaFulfillmentService $fulfillment)
    {
        $this->fulfillment = $fulfillment;
    }

    /**
     * UI visibility only. Reissue() also checks fresh API data.
     */
    public static function canReissue(Order $order)
    {
        return trim((string) $order->rozetka_status) !== '' &&
            trim((string) $order->tracking_number) !== '' &&
            trim((string) $order->tracking_status_code) === '1' &&
            in_array(
                $order->rozetka_status,
                ['confirmed', 'shipping', 'problem'],
                true
            );
    }

    /**
     * Explicit, one-click reissue using existing order and cargo data.
     * Never automatically retry a previous reissue for the same TTN.
     */
    public function reissue(Order $order)
    {
        if (!config('services.rozetka.actions_enabled', false)) {
            throw new RuntimeException('Дії через Rozetka API вимкнені.');
        }

        if (!self::canReissue($order)) {
            throw new RuntimeException(
                'Перевипуск ТТН доступний лише для замовлень Rozetka ' .
                'з чинною ТТН та статусом Нової Пошти (код 1).'
            );
        }

        // Use same mutex as regular OrderTtnService::createForOrder().
        $lock = Cache::lock('rozetka-create-ttn-' . $order->id, 300);

        if (!$lock->get()) {
            throw new RuntimeException(
                'Для цього замовлення вже виконується операція з ТТН.'
            );
        }

        try {
            $previousNumber = trim((string) $order->tracking_number);

            // Short-circuit repeated clicks before any external GET.
            // Keep the unique DB constraint / insertOrIgnore below as
            // protection against concurrent requests and process crashes.
            if (RozetkaTtnReissueAttempt::query()
                ->where('order_id', $order->id)
                ->where('old_tracking_number', $previousNumber)
                ->exists()
            ) {
                throw new RuntimeException(
                    'Перевипуск цієї ТТН вже виконувався. ' .
                    'Перед повторною дією перевірте замовлення в Rozetka.'
                );
            }

            $detail = null;
            $order = $this->fulfillment->refreshFromApi($order, $detail);

            // Fail closed if the GET omits trackingStatusCode. A locally
            // cached code 1 is insufficient to replace an existing TTN.
            if (!is_array($detail) ||
                !array_key_exists('trackingStatusCode', $detail) ||
                trim((string) $detail['trackingStatusCode']) !== '1' ||
                trim((string) ($detail['ttn'] ?? '')) !== $previousNumber ||
                !self::canReissue($order)
            ) {
                throw new RuntimeException(
                    'Перевипуск скасовано: актуальна ТТН або її статус ' .
                    'змінилися в Rozetka. Оновіть замовлення.'
                );
            }

            // No editable form or new values: reuse the existing local
            // order, validated confirmed quantities and cargo dimensions.
            $seats = $this->fulfillment->buildCargoSeats($order);

            // Durable, atomic repeat guard, including after PHP crashes
            // or ambiguous API responses. One attempt per old TTN number.
            $inserted = DB::table('rozetka_ttn_reissue_attempts')
                ->insertOrIgnore([
                    'order_id' => $order->id,
                    'old_tracking_number' => $previousNumber,
                    'status' => 'processing',
                    'cargo_seats' => json_encode($seats),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            if (!$inserted) {
                throw new RuntimeException(
                    'Перевипуск цієї ТТН вже виконувався. ' .
                    'Перед повторною дією перевірте замовлення в Rozetka.'
                );
            }

            $attempt = RozetkaTtnReissueAttempt::query()
                ->where('order_id', $order->id)
                ->where('old_tracking_number', $previousNumber)
                ->firstOrFail();

            try {
                $result = $this->fulfillment->createTtn(
                    $order,
                    true,
                    $seats
                );

                $newNumber = trim((string) ($result['tracking_number'] ?? ''));

                // Tracking code of the previous TTN is no longer valid
                // after reissue; a later sync will supply fresh NP status.
                $order->update([
                    'tracking_status_code' => null,
                    'tracking_status' => null,
                ]);

                $attempt->update([
                    'status' => 'success',
                    'new_tracking_number' => $newNumber,
                    'error_message' => null,
                ]);

                return $result;
            } catch (Throwable $e) {
                // POST may have succeeded remotely despite a timeout/500.
                // This record permanently blocks blind retry for the old TTN.
                $attempt->update([
                    'status' => 'needs_review',
                    'error_message' => mb_substr($e->getMessage(), 0, 65000),
                ]);
                throw $e;
            }
        } finally {
            $lock->release();
        }
    }
}
