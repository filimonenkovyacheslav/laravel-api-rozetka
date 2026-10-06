<?php

namespace App\Services;

use App\Models\Order;
use App\Services\NovaPoshta\NovaPoshtaTtnService;
use App\Services\Rozetka\RozetkaFulfillmentService;
use RuntimeException;

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
        $status = trim(
            (string) $order->rozetka_status
        );

        /*
         * Новый/изменённый заказ сначала подтверждаем.
         *
         * buildConfirmPayload использует
         * reserved_quantity как количество,
         * которое менеджер подтверждает.
         */
        if (
            in_array(
                $status,
                ['new', 'updated'],
                true
            )
        ) {
            $this->rozetkaService
                ->confirmOrder($order);

            /*
             * После POST /confirm локальные
             * confirmed_quantity и rozetka_status
             * уже обновлены.
             */
            $order->refresh();
            $order->load('items');

            $status = trim(
                (string) $order->rozetka_status
            );
        }

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

        return $this->rozetkaService
            ->createTtn($order);
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
}