<?php

namespace App\Services;

use App\Models\Order;
use App\Services\NovaPoshta\NovaPoshtaTtnService;
use App\Services\Rozetka\RozetkaFulfillmentService;
use RuntimeException;
use App\Services\Rozetka\RozetkaApiException;
use Throwable;

class OrderTtnService
{
    /**
     * @var NovaPoshtaTtnService
     */
    private $novaPoshtaService;

    /**
     * @var RozetkaFulfillmentService
     */
    private $rozetkaService;

    public function __construct(
        NovaPoshtaTtnService $novaPoshtaService,
        RozetkaFulfillmentService $rozetkaService
    ) {
        $this->novaPoshtaService =
            $novaPoshtaService;

        $this->rozetkaService =
            $rozetkaService;
    }

    /**
     * Создать ТТН для заказа.
     *
     * Legacy-заказы:
     * Laravel -> Nova Poshta.
     *
     * Новые Rozetka-заказы:
     * Laravel -> Rozetka -> Nova Poshta.
     *
     * @param Order $order
     * @return array
     */
    public function createForOrder(Order $order)
    {
        if (!$this->usesRozetkaApi($order)) {
            return $this->novaPoshtaService
                ->createForOrder($order);
        }

        return $this->createForRozetkaOrder(
            $order
        );
    }

    /**
     * @param Order $order
     * @return array
     */
    private function createForRozetkaOrder(
        Order $order
    ) {
        /*
         * Перед любым POST сначала читаем реальное
         * состояние заказа в Rozetka.
         */
        $order = $this->rozetkaService
            ->refreshFromApi($order);

        /*
         * Например:
         *
         * прошлый POST /ttn завершился timeout,
         * но на сервере всё-таки успешно выполнился.
         *
         * Второй POST уже не нужен.
         */
        if ($this->hasTrackingNumber($order)) {
            return $this->existingTtnResult(
                $order
            );
        }

        $status = trim(
            (string) $order->rozetka_status
        );

        /*
         * Новый или изменённый заказ
         * сначала подтверждаем.
         */
        if (
            in_array(
                $status,
                [
                    'new',
                    'updated',
                ],
                true
            )
        ) {
            try {
                $this->rozetkaService
                    ->confirmOrder($order);

                $order->refresh();
                $order->load('items');
            } catch (Throwable $confirmException) {
                /*
                 * Возможно сервер успел выполнить confirm,
                 * но ответ до нас не дошёл.
                 *
                 * Проверяем фактическое состояние.
                 */
                try {
                    $order = $this->rozetkaService
                        ->refreshFromApi($order);
                } catch (Throwable $refreshException) {
                    report($refreshException);

                    throw $confirmException;
                }

                $statusAfterError = trim(
                    (string) $order->rozetka_status
                );

                /*
                 * Если Rozetka уже перевела заказ дальше,
                 * повторный confirm не нужен.
                 */
                if (
                    !in_array(
                        $statusAfterError,
                        [
                            'confirmed',
                            'shipping',
                            'problem',
                        ],
                        true
                    )
                ) {
                    throw $confirmException;
                }
            }
        }

        /*
         * После confirm ещё раз используем
         * фактический локальный статус.
         */
        $status = trim(
            (string) $order->rozetka_status
        );

        if (
            !in_array(
                $status,
                [
                    'confirmed',
                    'shipping',
                    'problem',
                ],
                true
            )
        ) {
            throw new RuntimeException(
                'Замовлення Rozetka має статус "' .
                $status .
                '", який не дозволяє створення ТТН.'
            );
        }

        /*
         * Между confirm и TTN могла появиться ТТН
         * из предыдущей операции.
         */
        if ($this->hasTrackingNumber($order)) {
            return $this->existingTtnResult(
                $order
            );
        }

        try {
            return $this->rozetkaService
                ->createTtn($order);
        } catch (RozetkaApiException $e) {
            /*
             * Контрольный GET нужен только тогда,
             * когда результат POST действительно неоднозначен:
             *
             * - HTTP 500;
             * - transport error / timeout.
             *
             * Для 4xx (включая 429) и однозначных 5xx
             * дополнительный GET не выполняем.
             */
            if (
                $e->getHttpStatus() !== 500 &&
                !$e->isTransportError()
            ) {
                throw $e;
            }
            /*
             * POST мог выполниться, несмотря на ошибку ответа.
             * Сначала сверяем заказ.
             */
            try {
                $order = $this->rozetkaService
                    ->refreshFromApi($order);
            } catch (Throwable $refreshException) {
                report($refreshException);
            }

            /*
             * ТТН появилась в Rozetka —
             * считаем операцию успешной и НЕ повторяем POST.
             */
            if (
                $order &&
                $this->hasTrackingNumber($order)
            ) {
                return $this->existingTtnResult(
                    $order
                );
            }

            /*
             * HTTP 500:
             * по документации ТТН могла быть создана
             * непосредственно в Новой Почте,
             * но не сохранена у Rozetka.
             *
             * Transport error / timeout:
             * также неизвестно, выполнил ли сервер POST.
             *
             * Автоматический retry запрещаем.
             */
            if (
                $e->getHttpStatus() === 500 ||
                $e->isTransportError()
            ) {
                throw new RuntimeException(
                    '[ROZETKA_TTN_UNCERTAIN] ' .
                    'Невідомо, чи була створена ТТН. ' .
                    'Перед повторною спробою потрібно ' .
                    'перевірити замовлення в Rozetka та Новій Пошті. ' .
                    'Причина: ' .
                    $e->getMessage(),
                    0,
                    $e
                );
            }

            /*
             * Например 503:
             * API однозначно сообщает, что ТТН не создана.
             *
             * Такие ошибки обычный retry пакета
             * сможет обработать позже.
             */
            throw $e;
        }
    }

    /**
     * Определить, относится ли заказ
     * к новому Rozetka Supplier API.
     *
     * @param Order $order
     * @return bool
     */
    public function usesRozetkaApi(
        Order $order
    ) {
        return trim(
            (string) $order->rozetka_status
        ) !== '';
    }

    /**
     * @param Order $order
     * @return bool
     */
    private function hasTrackingNumber(
        Order $order
    ) {
        return trim(
            (string) $order->tracking_number
        ) !== '';
    }

    /**
     * Результат для случая, когда ТТН уже существует
     * и повторный POST делать не нужно.
     *
     * @param Order $order
     * @return array
     */
    private function existingTtnResult(
        Order $order
    ) {
        return [
            'tracking_number' =>
                trim(
                    (string) $order->tracking_number
                ),

            'order_status' =>
                $order->rozetka_status,

            'shipping_point' =>
                null,

            'cargo_seats' =>
                [],

            'raw' =>
                [],

            'reconciled' =>
                true,
        ];
    }
}