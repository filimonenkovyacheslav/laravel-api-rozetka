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

    /**
     * @var RozetkaDocumentFileService
     */
    private $documentFiles;

    public function __construct(
        RozetkaClient $client,
        RozetkaDocumentFileService $documentFiles
    ) {
        $this->client = $client;
        $this->documentFiles = $documentFiles;
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
            'skipped' => 0,
            'failed' => 0,
            'documents_downloaded' => 0,
            'document_errors' => 0,
            'errors' => [],
        ];

        while (true) {
            /*
             * Новая версия Supplier API позволяет
             * сразу получить полную шапку заказа
             * и товары в списке.
             *
             * Это уменьшает количество HTTP-запросов:
             * отдельный GET /orders/{orderId}
             * для каждого нового/изменённого заказа
             * больше не требуется.
             */
            $query = array_filter([
                'dateFrom' =>
                    $filters['dateFrom'] ?? null,

                'dateTo' =>
                    $filters['dateTo'] ?? null,

                'status' =>
                    $filters['status'] ?? null,

                'page' =>
                    $page,

                'expand' =>
                    'details,products',
            ], function ($value) {
                return $value !== null &&
                    $value !== '';
            });

            $response = $this->client->getOrders(
                $query
            );

            $totalCount =
                isset($response['totalCount'])
                    ? (int) $response['totalCount']
                    : 0;

            if ($page === 1) {
                $result['total'] =
                    $totalCount;
            }

            $orders =
                isset($response['orders']) &&
                is_array($response['orders'])
                    ? $response['orders']
                    : [];

            if (empty($orders)) {
                break;
            }

            /*
             * Получаем существующие локальные заказы
             * одним запросом на всю страницу.
             *
             * API возвращает до 50 заказов на страницу,
             * поэтому нет смысла выполнять отдельный
             * SELECT по GUID для каждого заказа.
             */
            $orderIds = collect($orders)
                ->map(function ($summary) {
                    return isset(
                        $summary['orderId']
                    )
                        ? trim(
                            (string) $summary['orderId']
                        )
                        : '';
                })
                ->filter()
                ->unique()
                ->values();

            $existingOrders = Order::query()
                ->whereIn(
                    'guid',
                    $orderIds->all()
                )
                ->get()
                ->keyBy(function ($order) {
                    return (string) $order->guid;
                });

            foreach ($orders as $summary) {
                $orderId =
                    isset($summary['orderId'])
                        ? trim(
                            (string) $summary['orderId']
                        )
                        : '';

                if ($orderId === '') {
                    $result['failed']++;

                    $result['errors'][] =
                        'У списку Rozetka отримано ' .
                        'замовлення без orderId.';

                    continue;
                }

                $existingOrder =
                    $existingOrders->get(
                        $orderId
                    );

                /*
                 * Даже при expand список содержит
                 * updatedAt и orderStatus.
                 *
                 * Если локальная копия уже полностью
                 * синхронизирована и удалённый заказ
                 * с прошлого раза не изменился,
                 * повторно сохранять его не нужно.
                 */
                if (
                    !$this->shouldFetchDetails(
                        $summary,
                        $existingOrder
                    )
                ) {
                    $result['skipped']++;

                    /*
                     * A documentLink can already be in orders, but its
                     * PDF may not have been downloaded (backfill/retry).
                     * Do not require a change of status or updatedAt.
                     */
                    if (!$dryRun && $existingOrder) {
                        $this->downloadDocumentSafely(
                            $existingOrder,
                            $result
                        );
                    }

                    continue;
                }

                try {
                    /*
                     * Благодаря expand=details,products
                     * $summary уже является полной
                     * карточкой заказа для целей
                     * локальной синхронизации.
                     *
                     * Отдельный GET
                     * /dropshipping/orders/{orderId}
                     * здесь больше не выполняется.
                     */
                    $syncResult =
                        $this->saveOrder(
                            $summary,
                            $dryRun
                        );

                    $result['processed']++;

                    if ($syncResult['created']) {
                        $result['created']++;
                    } else {
                        $result['updated']++;
                    }

                    if (!$dryRun) {
                        $syncedOrder = Order::find(
                            $syncResult['id']
                        );

                        if ($syncedOrder) {
                            $this->downloadDocumentSafely(
                                $syncedOrder,
                                $result
                            );
                        }
                    }
                } catch (Throwable $e) {
                    $result['failed']++;

                    $result['errors'][] =
                        sprintf(
                            '%s: %s',
                            $orderId,
                            $e->getMessage()
                        );

                    report($e);
                }
            }

            /*
             * API возвращает по 50 заказов
             * на одну страницу.
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
     * Определить, нужно ли обрабатывать заказ
     * из expanded list-response.
     *
     * Историческое имя метода сохранено,
     * чтобы изменение было минимальным.
     *
     * @param array $summary
     * @param Order|null $existingOrder
     * @return bool
     */
    private function shouldFetchDetails(
        array $summary,
        $existingOrder
    ) {
        /*
         * Новый заказ.
         */
        if (!$existingOrder) {
            return true;
        }

        /*
         * Заказ существовал до нового API
         * либо ещё ни разу не был полноценно
         * синхронизирован через Supplier API.
         */
        if (!$existingOrder->rozetka_synced_at) {
            return true;
        }

        $remoteStatus = trim(
            (string) (
                $summary['orderStatus']
                ?? ''
            )
        );

        /*
         * При некорректном ответе безопаснее
         * обработать заказ повторно.
         */
        if ($remoteStatus === '') {
            return true;
        }

        $localStatus = trim(
            (string) $existingOrder->rozetka_status
        );

        /*
         * Статус изменился.
         */
        if ($remoteStatus !== $localStatus) {
            return true;
        }

        /*
         * Статус ЮЗД может поменяться без изменения orderStatus/updatedAt.
         */
        if (array_key_exists('uzdStatus', $summary) &&
            $summary['uzdStatus'] !== $existingOrder->uzd_status
        ) {
            return true;
        }

        /*
         * Nova Poshta tracking details from Rozetka can change
         * without a change to orderStatus or updatedAt.
         */
        if (
            array_key_exists('trackingStatusCode', $summary) &&
            (string) ($summary['trackingStatusCode'] ?? '') !==
                (string) ($existingOrder->tracking_status_code ?? '')
        ) {
            return true;
        }

        if (
            array_key_exists('trackingStatus', $summary) &&
            trim((string) ($summary['trackingStatus'] ?? '')) !==
                trim((string) ($existingOrder->tracking_status ?? ''))
        ) {
            return true;
        }

        /*
         * The document URL can appear after TTN creation without
         * a corresponding change in the order's updatedAt.
         */
        if (
            array_key_exists('documentLink', $summary) &&
            trim((string) $summary['documentLink']) !==
                trim((string) $existingOrder->document_link)
        ) {
            return true;
        }

        /*
         * updatedAt — основной признак изменения
         * содержимого заказа.
         *
         * Если API вообще не прислал это поле,
         * безопаснее обработать заказ повторно.
         */
        if (
            !array_key_exists(
                'updatedAt',
                $summary
            )
        ) {
            return true;
        }

        $remoteUpdatedAt =
            $this->parseDate(
                $summary['updatedAt']
            );

        $localUpdatedAt =
            $existingOrder->updated_at_partner;

        /*
         * updatedAt у ещё не изменявшегося заказа
         * может быть null.
         */
        if ($remoteUpdatedAt === null) {
            return $localUpdatedAt !== null;
        }

        if ($localUpdatedAt === null) {
            return true;
        }

        try {
            $localUpdatedAt =
                Carbon::parse(
                    $localUpdatedAt
                );
        } catch (Throwable $e) {
            /*
             * Если локальная дата по какой-то причине
             * повреждена, не рискуем пропустить изменения.
             */
            return true;
        }

        /*
         * Обработка нужна только тогда,
         * когда timestamps отличаются.
         */
        return !$remoteUpdatedAt->equalTo(
            $localUpdatedAt
        );
    }

    /**
     * Download errors must not roll back an otherwise successful
     * order synchronization. They are reported and retried on the
     * next sync, including for orders skipped as unchanged.
     */
    private function downloadDocumentSafely(
        Order $order,
        array &$result
    ) {
        try {
            if ($this->documentFiles->downloadIfNeeded($order)) {
                $result['documents_downloaded']++;
            }
        } catch (Throwable $e) {
            $result['document_errors']++;

            $result['errors'][] =
                'Документ замовлення ' .
                ($order->order_num ?: $order->guid) .
                ': ' . $e->getMessage();

            report($e);
        }
    }

    /**
     * Сохранить полный заказ Rozetka.
     *
     * Данные могут приходить как из:
     *
     * GET /dropshipping/orders/{orderId}
     *
     * так и из:
     *
     * GET /dropshipping/orders
     *     ?expand=details,products
     *
     * @param array $data
     * @param bool $dryRun
     * @return array
     */
    public function saveOrder(
        array $data,
        $dryRun = false
    ) {
        $orderId =
            isset($data['orderId'])
                ? trim(
                    (string) $data['orderId']
                )
                : '';

        if ($orderId === '') {
            throw new RuntimeException(
                'API Rozetka повернув ' .
                'замовлення без orderId.'
            );
        }

        $existingOrder = Order::query()
            ->where(
                'guid',
                $orderId
            )
            ->first();

        $isNew =
            !$existingOrder;

        /*
         * Dry-run получает реальные данные
         * expanded list-response,
         * но ничего в БД не изменяет.
         */
        if ($dryRun) {
            return [
                'created' =>
                    $isNew,

                'order_id' =>
                    $orderId,
            ];
        }

        return DB::transaction(
            function () use (
                $data,
                $orderId,
                $existingOrder,
                $isNew
            ) {
                $order =
                    $existingOrder
                        ?: new Order();

                $order->guid =
                    $orderId;

                $order->order_num =
                    $data['orderNum']
                    ?? null;

                /*
                 * Совместимость со старой админкой.
                 *
                 * Раньше номер РОЗ фактически
                 * хранился в comment.
                 */
                if (
                    empty($order->comment) &&
                    !empty($data['orderNum'])
                ) {
                    $order->comment =
                        (string) $data['orderNum'];
                }

                $order->rozetka_status =
                    $data['orderStatus']
                    ?? null;

                if (array_key_exists('uzdStatus', $data)) {
                    $order->uzd_status = $data['uzdStatus'];
                }

                $order->status =
                    $this->mapInternalStatus(
                        $data['orderStatus']
                        ?? null,

                        $order->status
                    );

                $order->delivery_type =
                    $data['deliveryAddressType']
                    ?? null;

                $order->cash_on_delivery =
                    $data['cashOnDelivery']
                    ?? null;

                $order->delivery_company =
                    $data['deliveryCompanyName']
                    ?? null;

                $order->delivery_address_id =
                    $data['deliveryAddressId']
                    ?? null;

                $order->customer_name =
                    $data['customerName']
                    ?? null;

                $order->delivery_city =
                    $data['deliveryCity']
                    ?? null;

                $order->delivery_street =
                    $data['deliveryStreet']
                    ?? null;

                $order->delivery_phone =
                    $data['deliveryPhone']
                    ?? null;

                /*
                 * Не очищаем существующую локальную
                 * ТТН пустым значением из API.
                 *
                 * Это особенно важно для legacy-заказов,
                 * которые могут попасть в широкое окно
                 * контрольной синхронизации.
                 */
                $remoteTtn = trim(
                    (string) (
                        $data['ttn']
                        ?? ''
                    )
                );

                if ($remoteTtn !== '') {
                    $order->tracking_number =
                        $remoteTtn;
                } elseif (
                    trim(
                        (string)
                        $order->tracking_number
                    ) === ''
                ) {
                    $order->tracking_number =
                        null;
                }

                if (array_key_exists('trackingStatusCode', $data)) {
                    $order->tracking_status_code =
                        $data['trackingStatusCode'];
                }

                if (array_key_exists('trackingStatus', $data)) {
                    $order->tracking_status =
                        $data['trackingStatus'];
                }

                $order->return_ttn =
                    $data['returnTtn']
                    ?? null;

                $order->document_link =
                    $data['documentLink']
                    ?? null;

                $order->incoming_invoice_number =
                    $data['incomingInvoiceNumber']
                    ?? null;

                $order->sn_required =
                    array_key_exists(
                        'snRequired',
                        $data
                    )
                        ? (bool)
                            $data['snRequired']
                        : null;

                $order->created_at_partner =
                    $this->parseDate(
                        $data['createdAt']
                        ?? null
                    );

                $order->updated_at_partner =
                    $this->parseDate(
                        $data['updatedAt']
                        ?? null
                    );

                $order->rozetka_synced_at =
                    now();

                /*
                 * Для нового заказа сохраняем
                 * реальную дату создания Rozetka
                 * также в стандартный created_at.
                 *
                 * Благодаря этому существующие
                 * фильтры админки продолжают работать.
                 */
                if (
                    $isNew &&
                    !empty($data['createdAt'])
                ) {
                    $createdAt =
                        $this->parseDate(
                            $data['createdAt']
                        );

                    if ($createdAt) {
                        $order->created_at =
                            $createdAt;
                    }
                }

                $order->save();

                $products =
                    isset($data['products']) &&
                    is_array($data['products'])
                        ? $data['products']
                        : [];

                $this->syncItems(
                    $order,
                    $products
                );

                return [
                    'created' =>
                        $isNew,

                    'order_id' =>
                        $order->guid,

                    'id' =>
                        $order->id,
                ];
            }
        );
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
            $supplierCode =
                isset(
                    $product['supplierCode']
                )
                    ? trim(
                        (string)
                        $product['supplierCode']
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

            $isNewItem =
                !$item;

            if (!$item) {
                $item =
                    new OrderItem();

                $item->order_id =
                    $order->id;

                $item->supplier_code =
                    $supplierCode;

                /*
                 * В новом Dropshipping API
                 * цена позиции отсутствует.
                 */
                $item->price =
                    null;
            }

            /*
             * supplierCode сохраняем без дополнительной
             * нормализации.
             *
             * Это значение должно совпадать
             * с артикулом поставщика в Rozetka.
             */
            $item->supplier_code =
                $supplierCode;

            $item->rz_code =
                isset($product['rzCode'])
                    ? trim(
                        (string)
                        $product['rzCode']
                    )
                    : null;

            $quantity =
                isset($product['quantity'])
                    ? (int)
                        $product['quantity']
                    : 0;

            $item->quantity =
                $quantity;

            /*
             * confirmedQuantity может быть null
             * до подтверждения заказа.
             */
            if (
                array_key_exists(
                    'confirmedQuantity',
                    $product
                )
            ) {
                $item->confirmed_quantity =
                    $product[
                        'confirmedQuantity'
                    ] !== null
                        ? (int)
                            $product[
                                'confirmedQuantity'
                            ]
                        : null;

                /*
                 * Для совместимости со старым UI:
                 * если Rozetka уже вернула
                 * confirmedQuantity, показываем
                 * её также как reserved_quantity.
                 */
                if (
                    $product[
                        'confirmedQuantity'
                    ] !== null
                ) {
                    $item->reserved_quantity =
                        (int)
                            $product[
                                'confirmedQuantity'
                            ];
                } elseif ($isNewItem) {
                    /*
                     * Для нового неподтверждённого
                     * заказа по умолчанию считаем,
                     * что менеджер планирует
                     * подтвердить всё количество.
                     */
                    $item->reserved_quantity =
                        $quantity;
                }
            } elseif ($isNewItem) {
                $item->confirmed_quantity =
                    null;

                $item->reserved_quantity =
                    $quantity;
            }

            /*
             * Серийные номера синхронизируем,
             * если API действительно вернул поле.
             */
            if (
                array_key_exists(
                    'serialNumbers',
                    $product
                )
            ) {
                $serialNumbers =
                    is_array(
                        $product['serialNumbers']
                    )
                        ? array_values(
                            $product[
                                'serialNumbers'
                            ]
                        )
                        : [];

                $item->serial_number =
                    empty($serialNumbers)
                        ? null
                        : json_encode(
                            $serialNumbers,
                            JSON_UNESCAPED_UNICODE
                        );
            }

            if (
                array_key_exists(
                    'cargoSeats',
                    $product
                )
            ) {
                $item->rozetka_cargo_seats =
                    is_array($product['cargoSeats'])
                        ? array_values(
                            $product['cargoSeats']
                        )
                        : [];
            }

            $item->save();

            $savedItemIds[] =
                $item->id;
        }

        /*
         * Expanded list-response с products
         * и full-detail endpoint возвращают
         * полный состав заказа.
         *
         * Позиции, которых больше нет
         * в ответе Rozetka, удаляем локально.
         */
        $deleteQuery =
            OrderItem::query()
                ->where(
                    'order_id',
                    $order->id
                );

        if (!empty($savedItemIds)) {
            $deleteQuery->whereNotIn(
                'id',
                $savedItemIds
            );
        }

        $deleteQuery->delete();
    }

    /**
     * Сопоставить новый статус Rozetka
     * со старым внутренним статусом,
     * который использует существующая админка.
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
                return 'shipped';

            case 'closed':
                return 'shipped';

            case 'delivery_refused':
                return 'shipped';

            case 'problem':
                return 'updated';

            default:
                return $currentStatus
                    ?: 'created';
        }
    }

    /**
     * Разобрать дату Rozetka.
     *
     * @param mixed $value
     * @return Carbon|null
     */
    private function parseDate($value)
    {
        if (
            $value === null ||
            trim((string) $value) === ''
        ) {
            return null;
        }

        try {
            return Carbon::parse(
                (string) $value
            );
        } catch (Throwable $e) {
            throw new RuntimeException(
                'API Rozetka повернув ' .
                'некоректну дату "' .
                (string) $value .
                '".',
                0,
                $e
            );
        }
    }
}