<?php

namespace App\Services\Rozetka;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductDimension;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RozetkaFulfillmentService
{
    /**
     * @var RozetkaClient
     */
    private $client;

    /**
     * @var array
     */
    private $dimensionCache = [];

    public function __construct(RozetkaClient $client)
    {
        $this->client = $client;
    }

    /**
     * Обновить локальное состояние заказа
     * актуальными данными из Rozetka.
     *
     * Используется перед повторными изменяющими действиями.
     *
     * @param Order $order
     * @return Order
     */
    public function refreshFromApi(Order $order)
    {
        $orderId = $this->getOrderId($order);

        $detail = $this->client->getOrder(
            $orderId
        );

        DB::transaction(function () use (
            $order,
            $detail
        ) {
            if (
                array_key_exists(
                    'orderStatus',
                    $detail
                )
            ) {
                $order->rozetka_status =
                    $detail['orderStatus'];
            }

            if (
                array_key_exists(
                    'orderNum',
                    $detail
                )
            ) {
                $order->order_num =
                    $detail['orderNum'];
            }

            /*
             * Не очищаем существующую локальную ТТН,
             * если API сейчас вернул null.
             *
             * Но если Rozetka вернула номер —
             * обязательно синхронизируем его.
             */
            $remoteTtn = trim(
                (string) (
                    $detail['ttn']
                    ?? ''
                )
            );

            if ($remoteTtn !== '') {
                $order->tracking_number =
                    $remoteTtn;

                $order->status =
                    'shipped';
            }

            if (
                array_key_exists(
                    'trackingStatusCode',
                    $detail
                )
            ) {
                $order->tracking_status_code =
                    $detail['trackingStatusCode'];
            }

            if (
                array_key_exists(
                    'trackingStatus',
                    $detail
                )
            ) {
                $order->tracking_status =
                    $detail['trackingStatus'];
            }

            if (
                array_key_exists(
                    'returnTtn',
                    $detail
                )
            ) {
                $order->return_ttn =
                    $detail['returnTtn'];
            }

            if (
                array_key_exists(
                    'documentLink',
                    $detail
                )
            ) {
                $order->document_link =
                    $detail['documentLink'];
            }

            if (
                array_key_exists(
                    'incomingInvoiceNumber',
                    $detail
                )
            ) {
                $order->incoming_invoice_number =
                    $detail['incomingInvoiceNumber'];
            }

            if (
                array_key_exists(
                    'snRequired',
                    $detail
                )
            ) {
                $order->sn_required =
                    (bool) $detail['snRequired'];
            }

            $order->rozetka_synced_at =
                now();

            $order->save();

            $products =
                isset($detail['products']) &&
                is_array($detail['products'])
                    ? $detail['products']
                    : [];

            foreach ($products as $product) {
                $supplierCode = trim(
                    (string) (
                        $product['supplierCode']
                        ?? ''
                    )
                );

                if ($supplierCode === '') {
                    continue;
                }

                $item = OrderItem::query()
                    ->where(
                        'order_id',
                        $order->id
                    )
                    ->where(
                        'supplier_code',
                        $supplierCode
                    )
                    ->first();

                if (!$item) {
                    continue;
                }

                if (
                    array_key_exists(
                        'confirmedQuantity',
                        $product
                    )
                ) {
                    $item->confirmed_quantity =
                        $product['confirmedQuantity'] !== null
                            ? (int) $product['confirmedQuantity']
                            : null;
                }

                if (
                    isset($product['serialNumbers']) &&
                    is_array(
                        $product['serialNumbers']
                    )
                ) {
                    $item->serial_number =
                        json_encode(
                            array_values(
                                $product['serialNumbers']
                            ),
                            JSON_UNESCAPED_UNICODE
                        );
                }

                /*
                 * Сохраняем исходные cargoSeats,
                 * которые вернула Rozetka.
                 *
                 * Они используются только для проверки
                 * перед созданием ТТН.
                 *
                 * Источником payload для ТТН по-прежнему
                 * остаются наши ProductDimension.
                 */
                if (
                    array_key_exists(
                        'cargoSeats',
                        $product
                    )
                ) {
                    $item->rozetka_cargo_seats =
                        is_array(
                            $product['cargoSeats']
                        )
                            ? array_values(
                                $product['cargoSeats']
                            )
                            : [];
                }

                $item->save();
            }
        });

        return $order->fresh([
            'items',
        ]);
    }

    /**
     * Подготовить payload подтверждения заказа.
     *
     * Никаких запросов к Rozetka здесь нет.
     *
     * @param Order $order
     * @return array
     */
    public function buildConfirmPayload(Order $order)
    {
        $order->loadMissing('items');

        if ($order->items->isEmpty()) {
            throw new RuntimeException(
                'У замовленні немає товарних позицій.'
            );
        }

        $products = [];

        foreach ($order->items as $item) {
            $supplierCode = trim(
                (string) $item->supplier_code
            );

            if ($supplierCode === '') {
                throw new RuntimeException(
                    'У позиції #' .
                    $item->id .
                    ' не вказано supplier_code.'
                );
            }

            $quantity = (int) $item->quantity;

            if ($quantity < 1) {
                throw new RuntimeException(
                    'Товар ' .
                    $supplierCode .
                    ' має некоректну кількість.'
                );
            }

            /*
             * reserved_quantity используем как количество,
             * которое менеджер хочет подтвердить Rozetka.
             */
            $confirmedQuantity =
                $item->reserved_quantity !== null
                    ? (int) $item->reserved_quantity
                    : $quantity;

            if (
                $confirmedQuantity < 0 ||
                $confirmedQuantity > $quantity
            ) {
                throw new RuntimeException(
                    'Підтверджена кількість товару ' .
                    $supplierCode .
                    ' повинна бути від 0 до ' .
                    $quantity .
                    '.'
                );
            }

            $serialNumbers =
                $this->decodeSerialNumbers(
                    $item->serial_number
                );

            /*
             * Если заказ требует SN, количество серийников
             * должно совпадать с подтверждаемым количеством.
             */
            if (
                $order->sn_required &&
                count($serialNumbers) !==
                    $confirmedQuantity
            ) {
                throw new RuntimeException(
                    'Для товару ' .
                    $supplierCode .
                    ' потрібно вказати ' .
                    $confirmedQuantity .
                    ' серійних номерів. Зараз: ' .
                    count($serialNumbers) .
                    '.'
                );
            }

            /*
             * Если SN уже заполнены, не допускаем очевидно
             * неконсистентный payload.
             */
            if (
                !empty($serialNumbers) &&
                count($serialNumbers) !==
                    $confirmedQuantity
            ) {
                throw new RuntimeException(
                    'Кількість серійних номерів товару ' .
                    $supplierCode .
                    ' не відповідає confirmedQuantity.'
                );
            }

            $products[] = [
                'supplierCode' =>
                    $supplierCode,

                'confirmedQuantity' =>
                    $confirmedQuantity,

                'serialNumbers' =>
                    $serialNumbers,
            ];
        }

        return [
            'products' =>
                $products,
        ];
    }

    /**
     * Реально подтвердить заказ в Rozetka.
     *
     * @param Order $order
     * @return array
     */
    public function confirmOrder(Order $order)
    {
        $this->ensureActionsEnabled();

        $orderId =
            $this->getOrderId(
                $order
            );

        $payload =
            $this->buildConfirmPayload(
                $order
            );

        $response =
            $this->client->confirmOrder(
                $orderId,
                $payload['products']
            );

        DB::transaction(function () use (
            $order,
            $response
        ) {
            if (
                !empty(
                    $response['orderStatus']
                )
            ) {
                $order->rozetka_status =
                    (string)
                    $response['orderStatus'];
            }

            $order->rozetka_synced_at =
                now();

            $order->save();

            $products =
                isset($response['products']) &&
                is_array($response['products'])
                    ? $response['products']
                    : [];

            foreach ($products as $product) {
                $supplierCode = trim(
                    (string) (
                        $product['supplierCode']
                        ?? ''
                    )
                );

                if ($supplierCode === '') {
                    continue;
                }

                $item = OrderItem::query()
                    ->where(
                        'order_id',
                        $order->id
                    )
                    ->where(
                        'supplier_code',
                        $supplierCode
                    )
                    ->first();

                if (!$item) {
                    continue;
                }

                if (
                    array_key_exists(
                        'confirmedQuantity',
                        $product
                    )
                ) {
                    $item->confirmed_quantity =
                        $product['confirmedQuantity'] !== null
                            ? (int)
                                $product['confirmedQuantity']
                            : null;

                    $item->reserved_quantity =
                        $item->confirmed_quantity;
                }

                if (
                    isset($product['serialNumbers']) &&
                    is_array(
                        $product['serialNumbers']
                    )
                ) {
                    $item->serial_number =
                        json_encode(
                            array_values(
                                $product['serialNumbers']
                            ),
                            JSON_UNESCAPED_UNICODE
                        );
                }

                /*
                 * Ответ confirm не обязан содержать
                 * cargoSeats.
                 *
                 * Сохранённые ранее данные Rozetka
                 * здесь намеренно не трогаем.
                 */
                $item->save();
            }
        });

        return $response;
    }

    /**
     * Сформировать cargoSeats из наших габаритов.
     *
     * Один ProductDimensionPlace = одно место.
     * При quantity = 2 набор мест повторяется дважды.
     *
     * @param Order $order
     * @return array
     */
    public function buildCargoSeats(Order $order)
    {
        return $this->buildCargoSeatsInternal(
            $order,
            true
        );
    }

    /**
     * Только для предварительной проверки.
     *
     * Не требует confirmed_quantity и ничего не отправляет
     * во внешний API.
     *
     * @param Order $order
     * @return array
     */
    public function previewCargoSeats(Order $order)
    {
        return $this->buildCargoSeatsInternal(
            $order,
            false
        );
    }

    /**
     * @param Order $order
     * @param bool $requireConfirmation
     * @return array
     */
    private function buildCargoSeatsInternal(
        Order $order,
        $requireConfirmation = true
    ) {
        $order->loadMissing('items');

        if ($order->items->isEmpty()) {
            throw new RuntimeException(
                'У замовленні немає товарних позицій.'
            );
        }

        $cargoSeats = [];

        foreach ($order->items as $item) {
            $quantity =
                (int) $item->quantity;

            if ($quantity < 1) {
                throw new RuntimeException(
                    'Товар ' .
                    $item->rz_code .
                    ' має некоректну кількість.'
                );
            }

            /*
             * Для реального создания ТТН обязательно
             * полное подтверждение количества.
             *
             * Preview этот контроль пропускает.
             */
            if ($requireConfirmation) {
                if (
                    $item->confirmed_quantity === null ||
                    (int) $item->confirmed_quantity !==
                        $quantity
                ) {
                    throw new RuntimeException(
                        'Товар ' .
                        $item->supplier_code .
                        ' ще не підтверджено повністю. ' .
                        'Замовлено: ' .
                        $quantity .
                        ', підтверджено: ' .
                        (
                            $item->confirmed_quantity === null
                                ? '—'
                                : $item->confirmed_quantity
                        ) .
                        '.'
                    );
                }
            }

            /*
             * Локальные места одной единицы товара.
             */
            $unitCargoSeats =
                $this->buildLocalCargoSeatsForItem(
                    $item
                );

            /*
             * cargoSeats в Rozetka относятся
             * к одной единице товара.
             *
             * Для quantity > 1 повторяем весь
             * набор мест каждой единицы.
             */
            for (
                $unit = 1;
                $unit <= $quantity;
                $unit++
            ) {
                foreach (
                    $unitCargoSeats
                    as $seat
                ) {
                    $cargoSeats[] =
                        $seat;
                }
            }
        }

        if (empty($cargoSeats)) {
            throw new RuntimeException(
                'Не вдалося сформувати cargoSeats.'
            );
        }

        return $cargoSeats;
    }

    /**
     * Получить локальные грузовые места
     * для ОДНОЙ единицы товара.
     *
     * @param OrderItem $item
     * @return array
     */
    private function buildLocalCargoSeatsForItem(
        OrderItem $item
    ) {
        $dimension =
            $this->findDimension(
                $item->rz_code
            );

        $dimension->loadMissing(
            'places'
        );

        $places =
            $dimension->places;

        /*
         * Если отдельные места отсутствуют,
         * используем общие размеры товара
         * как одно место.
         */
        if ($places->isEmpty()) {
            $places = collect([
                (object) [
                    'weight' =>
                        $dimension->weight,

                    'length' =>
                        $dimension->length,

                    'width' =>
                        $dimension->width,

                    'height' =>
                        $dimension->height,
                ],
            ]);
        }

        $result = [];

        foreach ($places as $place) {
            $result[] =
                $this->makeCargoSeat(
                    $place,
                    $item->rz_code
                );
        }

        return $result;
    }

    /**
     * Создать ТТН через Rozetka.
     *
     * @param Order $order
     * @param bool $replaceExisting
     * @return array
     */
    public function createTtn(
        Order $order,
        $replaceExisting = false
    ) {
        $this->ensureActionsEnabled();

        $order->loadMissing('items');

        $orderId =
            $this->getOrderId(
                $order
            );

        $allowedStatuses = [
            'confirmed',
            'shipping',
            'problem',
        ];

        if (
            !in_array(
                $order->rozetka_status,
                $allowedStatuses,
                true
            )
        ) {
            throw new RuntimeException(
                'Статус Rozetka "' .
                $order->rozetka_status .
                '" не дозволяє створення ТТН.'
            );
        }

        if (
            !$replaceExisting &&
            trim(
                (string)
                $order->tracking_number
            ) !== ''
        ) {
            throw new RuntimeException(
                'Замовлення вже має ТТН ' .
                $order->tracking_number .
                '.'
            );
        }

        $this->validateSerialNumbersForTtn(
            $order
        );

        /*
         * Payload для создания ТТН формируем
         * из наших локальных ProductDimension.
         */
        $cargoSeats =
            $this->buildCargoSeats(
                $order
            );

        $response =
            $this->client->createTtn(
                $orderId,
                $cargoSeats,
                $replaceExisting
            );

        $trackingNumber = trim(
            (string) (
                $response['ttn']
                ?? ''
            )
        );

        if ($trackingNumber === '') {
            throw new RuntimeException(
                'Rozetka не повернула номер ТТН.'
            );
        }

        DB::transaction(function () use (
            $order,
            $response,
            $trackingNumber
        ) {
            $order->tracking_number =
                $trackingNumber;

            $order->ttn_created_at =
                now();

            $order->status =
                'shipped';

            $order->rozetka_status =
                $response['orderStatus']
                ?? 'shipping';

            $order->rozetka_synced_at =
                now();

            $order->save();
        });

        return [
            'tracking_number' =>
                $trackingNumber,

            'order_status' =>
                $response['orderStatus']
                ?? null,

            'shipping_point' =>
                $response['shippingPoint']
                ?? null,

            'cargo_seats' =>
                $cargoSeats,

            'raw' =>
                $response,
        ];
    }

    /**
     * @param Order $order
     * @return void
     */
    private function validateSerialNumbersForTtn(
        Order $order
    ) {
        if (!$order->sn_required) {
            return;
        }

        foreach ($order->items as $item) {
            $confirmedQuantity =
                (int)
                $item->confirmed_quantity;

            $serialNumbers =
                $this->decodeSerialNumbers(
                    $item->serial_number
                );

            if (
                count($serialNumbers) !==
                $confirmedQuantity
            ) {
                throw new RuntimeException(
                    'Для товару ' .
                    $item->supplier_code .
                    ' потрібно ' .
                    $confirmedQuantity .
                    ' серійних номерів. Зараз: ' .
                    count($serialNumbers) .
                    '.'
                );
            }
        }
    }

    /**
     * @param mixed $value
     * @return array
     */
    private function decodeSerialNumbers(
        $value
    ) {
        if (is_array($value)) {
            return array_values(
                array_filter(
                    $value,
                    function ($item) {
                        return trim(
                            (string) $item
                        ) !== '';
                    }
                )
            );
        }

        $value =
            trim(
                (string) $value
            );

        if ($value === '') {
            return [];
        }

        $decoded =
            json_decode(
                $value,
                true
            );

        if (is_array($decoded)) {
            return array_values(
                array_filter(
                    $decoded,
                    function ($item) {
                        return trim(
                            (string) $item
                        ) !== '';
                    }
                )
            );
        }

        /*
         * Совместимость со старыми данными:
         * один SN мог быть сохранён простой строкой.
         */
        return [
            $value,
        ];
    }

    /**
     * @param mixed $place
     * @param mixed $rzCode
     * @return array
     */
    private function makeCargoSeat(
        $place,
        $rzCode
    ) {
        $weight =
            (float) $place->weight;

        $length =
            (float) $place->length;

        $width =
            (float) $place->width;

        $height =
            (float) $place->height;

        if (
            $weight <= 0 ||
            $length <= 0 ||
            $width <= 0 ||
            $height <= 0
        ) {
            throw new RuntimeException(
                'Для товару ' .
                $rzCode .
                ' не заповнено вагу або габарити.'
            );
        }

        return [
            'lengthCm' =>
                round(
                    $length,
                    2
                ),

            'widthCm' =>
                round(
                    $width,
                    2
                ),

            'heightCm' =>
                round(
                    $height,
                    2
                ),

            'weightKg' =>
                round(
                    $weight,
                    3
                ),
        ];
    }

    /**
     * @param mixed $rzCode
     * @return ProductDimension
     */
    private function findDimension(
        $rzCode
    ) {
        $code =
            $this->normalizeProductCode(
                $rzCode
            );

        if ($code === '') {
            throw new RuntimeException(
                'У товару не вказано RZ code.'
            );
        }

        if (
            isset(
                $this->dimensionCache[$code]
            )
        ) {
            return
                $this->dimensionCache[$code];
        }

        $dimension =
            ProductDimension::query()
                ->with('places')
                ->where(
                    'rz_code',
                    $code
                )
                ->first();

        if (!$dimension) {
            throw new RuntimeException(
                'Для товару з кодом ' .
                $code .
                ' не знайдено вагу та габарити.'
            );
        }

        $this->dimensionCache[$code] =
            $dimension;

        return $dimension;
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function normalizeProductCode(
        $value
    ) {
        $value =
            (string) $value;

        $value =
            str_replace(
                "\xC2\xA0",
                ' ',
                $value
            );

        return trim(
            $value
        );
    }

    /**
     * @param Order $order
     * @return string
     */
    private function getOrderId(
        Order $order
    ) {
        $orderId = trim(
            (string)
            $order->guid
        );

        if ($orderId === '') {
            throw new RuntimeException(
                'У замовленні відсутній orderId.'
            );
        }

        return $orderId;
    }

    /**
     * Запретить реальные POST-запросы,
     * пока явно не включим их в .env.
     *
     * @return void
     */
    private function ensureActionsEnabled()
    {
        if (
            !config(
                'services.rozetka.actions_enabled',
                false
            )
        ) {
            throw new RuntimeException(
                'Дії через Rozetka API вимкнені. ' .
                'ROZETKA_ACTIONS_ENABLED=false.'
            );
        }
    }
}