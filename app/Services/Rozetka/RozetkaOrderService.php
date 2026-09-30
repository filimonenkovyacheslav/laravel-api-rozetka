<?php

namespace App\Services\Rozetka;

use App\Models\Order;
use App\Models\OrderItem;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class RozetkaOrderService
{
    /**
     * @var RozetkaClient
     */
    private $client;

    public function __construct(RozetkaClient $client)
    {
        $this->client = $client;
    }

    /**
     * Синхронизировать заказы Rozetka.
     *
     * Поддерживаемые фильтры:
     *
     * dateFrom
     * dateTo
     * status
     *
     * @param array $filters
     * @param bool $dryRun
     * @return array
     */
    public function sync(
        array $filters = [],
        $dryRun = false
    ) {
        if (
            !$dryRun &&
            !config(
                'services.rozetka.sync_enabled',
                false
            )
        ) {
            throw new RuntimeException(
                'Синхронізація Rozetka вимкнена. ' .
                'Для запису встановіть ' .
                'ROZETKA_SYNC_ENABLED=true.'
            );
        }
        
        $page = 1;

        $result = [
            'total' => 0,
            'processed' => 0,
            'created' => 0,
            'updated' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        while (true) {
            $query = array_filter([
                'dateFrom' => $filters['dateFrom'] ?? null,
                'dateTo' => $filters['dateTo'] ?? null,
                'status' => $filters['status'] ?? null,
                'page' => $page,
            ], function ($value) {
                return $value !== null && $value !== '';
            });

            $response = $this->client->getOrders(
                $query
            );

            $totalCount = isset($response['totalCount'])
                ? (int) $response['totalCount']
                : 0;

            if ($page === 1) {
                $result['total'] = $totalCount;
            }

            $orders = isset($response['orders'])
                && is_array($response['orders'])
                    ? $response['orders']
                    : [];

            if (empty($orders)) {
                break;
            }

            foreach ($orders as $summary) {
                $orderId = isset($summary['orderId'])
                    ? trim((string) $summary['orderId'])
                    : '';

                if ($orderId === '') {
                    $result['failed']++;
                    $result['errors'][] =
                        'У списку Rozetka отримано замовлення без orderId.';

                    continue;
                }

                try {
                    $details = $this->client->getOrder(
                        $orderId
                    );

                    $syncResult = $this->saveOrder(
                        $details,
                        $dryRun
                    );

                    $result['processed']++;

                    if ($syncResult['created']) {
                        $result['created']++;
                    } else {
                        $result['updated']++;
                    }
                } catch (Throwable $e) {
                    $result['failed']++;

                    $result['errors'][] = sprintf(
                        '%s: %s',
                        $orderId,
                        $e->getMessage()
                    );

                    report($e);
                }
            }

            /*
             * API возвращает по 50 заказов на страницу.
             */
            if (
                $totalCount === 0 ||
                ($page * 50) >= $totalCount
            ) {
                break;
            }

            $page++;
        }

        return $result;
    }

    /**
     * Сохранить полный заказ Rozetka.
     *
     * @param array $data
     * @param bool $dryRun
     * @return array
     */
    public function saveOrder(
        array $data,
        $dryRun = false
    ) {
        $orderId = isset($data['orderId'])
            ? trim((string) $data['orderId'])
            : '';

        if ($orderId === '') {
            throw new RuntimeException(
                'API Rozetka повернув замовлення без orderId.'
            );
        }

        $existingOrder = Order::query()
            ->where('guid', $orderId)
            ->first();

        $isNew = !$existingOrder;

        if ($dryRun) {
            return [
                'created' => $isNew,
                'order_id' => $orderId,
            ];
        }

        return DB::transaction(function () use (
            $data,
            $orderId,
            $existingOrder,
            $isNew
        ) {
            $order = $existingOrder ?: new Order();

            $order->guid = $orderId;

            $order->order_num =
                $data['orderNum'] ?? null;

            /*
             * Для совместимости со старой админкой.
             * Раньше номер РОЗ фактически хранился в comment.
             */
            if (
                empty($order->comment) &&
                !empty($data['orderNum'])
            ) {
                $order->comment =
                    (string) $data['orderNum'];
            }

            $order->rozetka_status =
                $data['orderStatus'] ?? null;

            $order->status = $this->mapInternalStatus(
                $data['orderStatus'] ?? null,
                $order->status
            );

            $order->delivery_type =
                $data['deliveryAddressType'] ?? null;

            $order->cash_on_delivery =
                $data['cashOnDelivery'] ?? null;

            $order->delivery_company =
                $data['deliveryCompanyName'] ?? null;

            $order->delivery_address_id =
                $data['deliveryAddressId'] ?? null;

            $order->customer_name =
                $data['customerName'] ?? null;

            $order->delivery_city =
                $data['deliveryCity'] ?? null;

            $order->delivery_street =
                $data['deliveryStreet'] ?? null;

            $order->delivery_phone =
                $data['deliveryPhone'] ?? null;

            $order->tracking_number =
                $data['ttn'] ?? null;

            $order->tracking_status_code =
                $data['trackingStatusCode'] ?? null;

            $order->tracking_status =
                $data['trackingStatus'] ?? null;

            $order->return_ttn =
                $data['returnTtn'] ?? null;

            $order->document_link =
                $data['documentLink'] ?? null;

            $order->incoming_invoice_number =
                $data['incomingInvoiceNumber'] ?? null;

            $order->sn_required =
                array_key_exists('snRequired', $data)
                    ? (bool) $data['snRequired']
                    : null;

            $order->created_at_partner =
                $this->parseDate(
                    $data['createdAt'] ?? null
                );

            $order->updated_at_partner =
                $this->parseDate(
                    $data['updatedAt'] ?? null
                );

            $order->rozetka_synced_at = now();

            /*
             * Для новых заказов сохраняем реальную дату
             * создания заказа Rozetka и в created_at.
             *
             * Тогда старые фильтры админки продолжат
             * корректно работать.
             */
            if ($isNew && !empty($data['createdAt'])) {
                $createdAt = $this->parseDate(
                    $data['createdAt']
                );

                if ($createdAt) {
                    $order->created_at = $createdAt;
                }
            }

            $order->save();

            $this->syncItems(
                $order,
                isset($data['products'])
                && is_array($data['products'])
                    ? $data['products']
                    : []
            );

            return [
                'created' => $isNew,
                'order_id' => $order->guid,
                'id' => $order->id,
            ];
        });
    }

    /**
     * Синхронизировать позиции заказа.
     *
     * @param Order $order
     * @param array $products
     * @return void
     */
    private function syncItems(
        Order $order,
        array $products
    ) {
        $savedItemIds = [];

        foreach ($products as $product) {
            $supplierCode = isset(
                $product['supplierCode']
            )
                ? trim(
                    (string) $product['supplierCode']
                )
                : '';

            if ($supplierCode === '') {
                throw new RuntimeException(
                    'Товар у замовленні ' .
                    $order->guid .
                    ' не містить supplierCode.'
                );
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

            $isNewItem = !$item;

            if (!$item) {
                $item = new OrderItem();
                $item->order_id = $order->id;
                $item->supplier_code =
                    $supplierCode;
            }

            $item->rz_code =
                array_key_exists('rzCode', $product)
                && $product['rzCode'] !== null
                    ? (string) $product['rzCode']
                    : null;

            $item->quantity =
                isset($product['quantity'])
                    ? (int) $product['quantity']
                    : 0;

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

            /*
             * reserved_quantity пока оставляем для
             * совместимости со старой админкой.
             *
             * Новый заказ по умолчанию считаем полностью
             * зарезервированным.
             *
             * Если Rozetka уже вернула confirmedQuantity,
             * отражаем это также в старом поле.
             */
            if (
                array_key_exists(
                    'confirmedQuantity',
                    $product
                ) &&
                $product['confirmedQuantity'] !== null
            ) {
                $item->reserved_quantity =
                    (int) $product['confirmedQuantity'];
            } elseif (
                $isNewItem &&
                $item->reserved_quantity === null
            ) {
                $item->reserved_quantity =
                    (int) $item->quantity;
            }

            /*
             * Новый API цену заказа не возвращает.
             *
             * Для существующей позиции старую цену
             * не трогаем.
             *
             * Для новой она останется NULL.
             */
            if ($isNewItem) {
                $item->price = null;
            }

            $serialNumbers =
                isset($product['serialNumbers'])
                && is_array($product['serialNumbers'])
                    ? $product['serialNumbers']
                    : [];

            $item->serial_number = json_encode(
                array_values($serialNumbers),
                JSON_UNESCAPED_UNICODE
            );

            $item->save();

            $savedItemIds[] = $item->id;
        }

        /*
         * Если Rozetka удалила позицию из заказа,
         * локально она тоже больше не должна оставаться.
         */
        $deleteQuery = OrderItem::query()
            ->where('order_id', $order->id);

        if (!empty($savedItemIds)) {
            $deleteQuery->whereNotIn(
                'id',
                $savedItemIds
            );
        }

        $deleteQuery->delete();
    }

    /**
     * Оставляем старый status для действующей админки.
     * Отдельный rozetka_status является источником
     * состояния нового API.
     *
     * @param string|null $rozetkaStatus
     * @param string|null $currentStatus
     * @return string
     */
    private function mapInternalStatus(
        $rozetkaStatus,
        $currentStatus = null
    ) {
        switch ($rozetkaStatus) {
            case 'new':
                return 'created';

            case 'updated':
                return 'updated';

            case 'deleted':
                return 'canceled';

            case 'confirmed':
                return 'created';

            case 'shipping':
            case 'closed':
            case 'delivery_refused':
                return 'shipped';

            case 'problem':
                return 'updated';

            default:
                return $currentStatus ?: 'created';
        }
    }

    /**
     * @param mixed $value
     * @return Carbon|null
     */
    private function parseDate($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable $e) {
            return null;
        }
    }
}