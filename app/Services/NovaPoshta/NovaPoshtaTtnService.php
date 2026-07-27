<?php

namespace App\Services\NovaPoshta;

use App\Models\Order;
use App\Models\ProductDimension;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use App\Models\TtnBatch;
use App\Models\TtnBatchOrder;

class NovaPoshtaTtnService
{
    /**
     * @var NovaPoshtaClient
     */
    private $client;

    /**
     * Кэш характеристик товаров внутри одного запроса.
     *
     * @var array
     */
    private $dimensionCache = [];

    public function __construct(NovaPoshtaClient $client)
    {
        $this->client = $client;
    }

    /**
     * Создать ТТН для одного заказа.
     *
     * @return array
     */
    public function createForOrder(Order $order)
    {
        $order->loadMissing('items');

        $this->validateOrder($order);

        $phone = $this->normalizePhone(
            $order->delivery_phone
        );

        /*
         * Определяем тип получения:
         *
         * warehouse — в отделении;
         * doors     — курьером по адресу.
         */
        $recipientContext = $this->buildRecipientContext(
            $order,
            $phone
        );

        $cargo = $this->buildCargo($order);

        $properties = $this->buildDocumentProperties(
            $order,
            $recipientContext,
            $phone,
            $cargo
        );

        /*
         * Создаётся настоящая электронная накладная.
         */
        $documents = $this->client->call(
            'InternetDocument',
            'save',
            $properties
        );

        if (empty($documents[0]) || !is_array($documents[0])) {
            throw new RuntimeException(
                'Нова Пошта не повернула дані створеної ТТН.'
            );
        }

        $document = $documents[0];

        $trackingNumber = trim(
            (string) ($document['IntDocNumber'] ?? '')
        );

        $documentRef = trim(
            (string) ($document['Ref'] ?? '')
        );

        if ($trackingNumber === '') {
            throw new RuntimeException(
                'Нова Пошта створила документ без номера ТТН.'
            );
        }

        if (!$this->isUuid($documentRef)) {
            throw new RuntimeException(
                'Нова Пошта не повернула коректний Ref документа.'
            );
        }

        DB::transaction(function () use (
            $order,
            $trackingNumber,
            $documentRef,
            $recipientContext,
            $properties
        ) {
            /*
             * Для адресной доставки Ref города, отделения,
             * контрагента и контакта API может не возвращать,
             * потому что получатель передаётся строками.
             */
            $order->forceFill([
                'tracking_number' => $trackingNumber,
                'ttn_created_at' => now(),
                'status' => 'shipped',

                'np_service_type' =>
                    $properties['ServiceType'],

                'np_city_ref' =>
                    $recipientContext['city_ref'] ?? null,

                'np_recipient_settlement_ref' =>
                    $recipientContext['settlement_ref'] ?? null,

                'np_recipient_street_ref' =>
                    $recipientContext['street_ref'] ?? null,

                'np_recipient_street_name' =>
                    $recipientContext['street'] ?? null,

                'np_recipient_street_source' =>
                    $recipientContext['street_source'] ?? null,

                'np_warehouse_ref' =>
                    $recipientContext['warehouse_ref'] ?? null,

                'np_recipient_ref' =>
                    $recipientContext['recipient_ref'] ?? null,

                'np_recipient_contact_ref' =>
                    $recipientContext['contact_ref'] ?? null,

                'np_recipient_address_ref' =>
                    $recipientContext['address_ref'] ?? null,

                'np_document_ref' => $documentRef,

            ])->save();
        });

        return [
            'tracking_number' => $trackingNumber,
            'document_ref' => $documentRef,

            'cost_on_site' =>
                $document['CostOnSite'] ?? null,

            'estimated_delivery_date' =>
                $document['EstimatedDeliveryDate'] ?? null,

            'service_type' =>
                $properties['ServiceType'],

            'recipient_type' =>
                $recipientContext['type'],

            'seats_amount' =>
                $cargo['seats_amount'],

            'weight' =>
                $cargo['total_weight'],
        ];
    }

    private function buildRecipientContext(
        Order $order,
        $phone
    ) {
        $deliveryAddressId = trim(
            (string) $order->delivery_address_id
        );

        /*
         * =========================================================
         * 1. ДОСТАВКА В ОТДЕЛЕНИЕ
         * =========================================================
         *
         * UUID в delivery_address_id — Ref отделения Новой Почты.
         */
        if ($this->isUuid($deliveryAddressId)) {
            $warehouse = $this->resolveRecipientWarehouse(
                $deliveryAddressId
            );

            $cityRecipientRef = $this->extractCityRef(
                $warehouse
            );

            $name = $this->parseCustomerName(
                $order->customer_name
            );

            $recipient = $this->createRecipient(
                $cityRecipientRef,
                $name,
                $phone
            );

            return [
                'type' => 'warehouse',
                'address_mode' => 'warehouse',

                'city_ref' => $cityRecipientRef,

                'settlement_ref' => null,

                'warehouse_ref' =>
                    trim((string) ($warehouse['Ref'] ?? '')),

                'street_ref' => null,
                'street_source' => null,

                'address_ref' => null,

                'recipient_ref' =>
                    $recipient['recipient_ref'],

                'contact_ref' =>
                    $recipient['contact_ref'],

                'recipient_name' =>
                    trim((string) $order->customer_name),

                'city' => trim(
                    (string) (
                        $warehouse['CityDescription']
                        ?? $order->delivery_city
                        ?? ''
                    )
                ),

                'street' => '',
                'house' => '',
                'flat' => '',
            ];
        }

        /*
         * =========================================================
         * 2. АДРЕСНАЯ ДОСТАВКА
         * =========================================================
         */

        $parsedAddress = $this->parseRecipientAddress(
            $order
        );

        /*
         * Сначала проверяем, уточнил ли менеджер
         * населённый пункт вручную.
         */
        $storedSettlementRef = trim(
            (string) $order->np_recipient_settlement_ref
        );

        $storedCityRef = trim(
            (string) $order->np_city_ref
        );

        if (
            $this->isUuid($storedSettlementRef)
            && $this->isUuid($storedCityRef)
        ) {
            $settlement = [
                'settlement_ref' =>
                    $storedSettlementRef,

                'city_ref' =>
                    $storedCityRef,

                'description' => trim(
                    (string) (
                        $order->np_recipient_settlement_name
                        ?: $order->delivery_city
                    )
                ),
            ];
        } else {
            /*
             * Ручного выбора нет.
             * Пытаемся определить населённый пункт автоматически.
             *
             * Метод должен вернуть:
             *
             * settlement_ref — для нового справочника улиц;
             * city_ref       — DeliveryCity для старого API.
             */
            $settlement = $this->resolveRecipientSettlement(
                $order->delivery_city
            );
        }

        $settlementRef = trim(
            (string) (
                $settlement['settlement_ref'] ?? ''
            )
        );

        $cityRef = trim(
            (string) (
                $settlement['city_ref'] ?? ''
            )
        );

        if (!$this->isUuid($settlementRef)) {
            throw new RuntimeException(
                'Не визначено SettlementRef населеного пункту.'
            );
        }

        if (!$this->isUuid($cityRef)) {
            throw new RuntimeException(
                'Не визначено CityRef населеного пункту.'
            );
        }

        /*
         * Проверяем сохранённый ручной выбор улицы.
         */
        $storedStreetRef = trim(
            (string) $order->np_recipient_street_ref
        );

        if ($this->isUuid($storedStreetRef)) {
            $street = [
                'ref' => $storedStreetRef,

                'description' => trim(
                    (string) (
                        $order->np_recipient_street_name
                        ?: $parsedAddress['street']
                    )
                ),

                'source' => trim(
                    (string) (
                        $order->np_recipient_street_source
                        ?: 'city'
                    )
                ),
            ];
        } else {
            /*
             * Автоматический поиск через старый справочник
             * Address/getStreet по DeliveryCity.
             *
             * Для небольших населённых пунктов, где этот
             * справочник пустой, заказ получит failed,
             * после чего менеджер выберет улицу вручную
             * через searchSettlementStreets.
             */
            $street = $this->resolveRecipientStreet(
                $cityRef,
                $parsedAddress['street']
            );

            $street['source'] = 'city';
        }

        $streetRef = trim(
            (string) ($street['ref'] ?? '')
        );

        $streetDescription = trim(
            (string) (
                $street['description']
                ?? $parsedAddress['street']
            )
        );

        $streetSource = trim(
            (string) (
                $street['source']
                ?? $order->np_recipient_street_source
                ?? 'city'
            )
        );

        if (!$this->isUuid($streetRef)) {
            throw new RuntimeException(
                'Не визначено коректний Ref вулиці.'
            );
        }

        if (!in_array(
            $streetSource,
            ['city', 'settlement'],
            true
        )) {
            throw new RuntimeException(
                'Невідоме джерело довідника вулиці: ' .
                $streetSource
            );
        }

        $recipientName = trim(
            (string) $order->customer_name
        );

        if ($recipientName === '') {
            throw new RuntimeException(
                'Не вказано ПІБ одержувача.'
            );
        }

        /*
         * =========================================================
         * 2.1. УЛИЦА ИЗ НОВОГО СПРАВОЧНИКА НАСЕЛЁННЫХ ПУНКТОВ
         * =========================================================
         *
         * SettlementStreetRef нельзя передавать в Address/save.
         * Адрес передадим непосредственно в InternetDocument/save.
         */
        if ($streetSource === 'settlement') {
            return [
                'type' => 'doors',
                'address_mode' => 'settlement',

                'city_ref' => $cityRef,

                'settlement_ref' =>
                    $settlementRef,

                'warehouse_ref' => null,

                'street_ref' =>
                    $streetRef,

                'street_source' =>
                    'settlement',

                'address_ref' => null,

                'recipient_ref' => null,
                'contact_ref' => null,

                'recipient_name' =>
                    $recipientName,

                'city' => trim(
                    (string) (
                        $settlement['description']
                        ?? $order->delivery_city
                    )
                ),

                'street' =>
                    $streetDescription,

                'house' =>
                    trim((string) $parsedAddress['house']),

                'flat' =>
                    trim((string) $parsedAddress['flat']),
            ];
        }

        /*
         * =========================================================
         * 2.2. УЛИЦА ИЗ СТАРОГО ГОРОДСКОГО СПРАВОЧНИКА
         * =========================================================
         *
         * Для Address/getStreet можно создать:
         *
         * Counterparty
         * → ContactPerson
         * → Address/save
         * → RecipientAddress Ref.
         */
        $name = $this->parseCustomerName(
            $order->customer_name
        );

        $recipient = $this->createRecipient(
            $cityRef,
            $name,
            $phone
        );

        try {
            $recipientAddress = $this->createRecipientAddress(
                $recipient['recipient_ref'],
                $streetRef,
                $parsedAddress['house'],
                $parsedAddress['flat']
            );
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'Не вдалося створити адресу одержувача. ' .
                'Населений пункт: "' .
                (
                    $settlement['description']
                    ?? $order->delivery_city
                ) .
                '", CityRef: ' .
                $cityRef .
                ', вулиця: "' .
                $streetDescription .
                '", StreetRef: ' .
                $streetRef .
                ', будинок: "' .
                $parsedAddress['house'] .
                '", квартира: "' .
                $parsedAddress['flat'] .
                '". Причина API: ' .
                $e->getMessage(),
                0,
                $e
            );
        }

        $addressRef = trim(
            (string) (
                $recipientAddress['address_ref'] ?? ''
            )
        );

        if (!$this->isUuid($addressRef)) {
            throw new RuntimeException(
                'Нова Пошта не повернула коректний Ref адреси одержувача.'
            );
        }

        return [
            'type' => 'doors',
            'address_mode' => 'counterparty',

            'city_ref' => $cityRef,

            'settlement_ref' =>
                $settlementRef,

            'warehouse_ref' => null,

            'street_ref' =>
                $streetRef,

            'street_source' =>
                'city',

            'address_ref' =>
                $addressRef,

            'recipient_ref' =>
                $recipient['recipient_ref'],

            'contact_ref' =>
                $recipient['contact_ref'],

            'recipient_name' =>
                $recipientName,

            'city' => trim(
                (string) (
                    $settlement['description']
                    ?? $order->delivery_city
                )
            ),

            'street' =>
                $streetDescription,

            'house' =>
                trim((string) $parsedAddress['house']),

            'flat' =>
                trim((string) $parsedAddress['flat']),
        ];
    }

    /**
     * Создать адрес получателя в Новой Почте.
     */
    private function createRecipientAddress(
        $counterpartyRef,
        $streetRef,
        $buildingNumber,
        $flat = ''
    ) {
        $counterpartyRef = trim(
            (string) $counterpartyRef
        );

        $streetRef = trim(
            (string) $streetRef
        );

        $buildingNumber = trim(
            (string) $buildingNumber
        );

        $flat = trim(
            (string) $flat
        );

        if (!$this->isUuid($counterpartyRef)) {
            throw new RuntimeException(
                'Некоректний Ref одержувача для створення адреси.'
            );
        }

        if (!$this->isUuid($streetRef)) {
            throw new RuntimeException(
                'Некоректний Ref вулиці для створення адреси.'
            );
        }

        if ($buildingNumber === '') {
            throw new RuntimeException(
                'Не вказано номер будинку одержувача.'
            );
        }

        $addresses = $this->client->call(
            'Address',
            'save',
            [
                'CounterpartyRef' =>
                    $counterpartyRef,

                'StreetRef' =>
                    $streetRef,

                'BuildingNumber' =>
                    $buildingNumber,

                /*
                 * API требует присутствия поля Flat,
                 * даже когда квартиры нет.
                 */
                'Flat' => $flat,

                'Note' => '',
            ]
        );

        if (
            empty($addresses[0]) ||
            !is_array($addresses[0])
        ) {
            throw new RuntimeException(
                'Нова Пошта не повернула створену адресу одержувача.'
            );
        }

        $address = $addresses[0];

        $addressRef = trim(
            (string) ($address['Ref'] ?? '')
        );

        if (!$this->isUuid($addressRef)) {
            throw new RuntimeException(
                'Нова Пошта не повернула коректний Ref адреси одержувача.'
            );
        }

        return [
            'address_ref' => $addressRef,

            'description' => trim(
                (string) ($address['Description'] ?? '')
            ),
        ];
    }

    private function resolveRecipientSettlement($deliveryCity)
    {
        $search = $this->normalizeSettlementSearch(
            $deliveryCity
        );

        if ($search === '') {
            throw new RuntimeException(
                'Не вказано населений пункт одержувача.'
            );
        }

        $response = $this->client->call(
            'AddressGeneral',
            'searchSettlements',
            [
                'CityName' => $search,
                'Limit' => '50',
                'Page' => '1',
            ]
        );

        /*
         * Ответ searchSettlements обычно имеет структуру:
         *
         * [
         *     [
         *         'TotalCount' => ...,
         *         'Addresses' => [...]
         *     ]
         * ]
         */
        $addresses = [];

        foreach ($response as $group) {
            if (
                is_array($group) &&
                !empty($group['Addresses']) &&
                is_array($group['Addresses'])
            ) {
                foreach ($group['Addresses'] as $address) {
                    if (is_array($address)) {
                        $addresses[] = $address;
                    }
                }
            }
        }

        if (empty($addresses)) {
            throw new RuntimeException(
                'Нова Пошта не знайшла населений пункт: ' .
                $deliveryCity
            );
        }

        $normalizedSearch = $this->normalizeComparableText(
            $search
        );

        $exactMatches = [];

        foreach ($addresses as $address) {
            $description = trim(
                (string) (
                    $address['MainDescription'] ?? ''
                )
            );

            if (
                $this->normalizeComparableText($description) ===
                $normalizedSearch
            ) {
                $exactMatches[] = $address;
            }
        }

        /*
         * Если точного совпадения нет, но API вернул
         * единственный вариант, используем его.
         */
        if (count($exactMatches) === 1) {
            $selected = $exactMatches[0];
        } elseif (
            count($exactMatches) === 0 &&
            count($addresses) === 1
        ) {
            $selected = $addresses[0];
        } else {
            $variants = [];

            $source = !empty($exactMatches)
                ? $exactMatches
                : $addresses;

            foreach ($source as $address) {
                $variants[] = trim(
                    (string) (
                        $address['MainDescription'] ?? ''
                    )
                ) .
                ' — ' .
                trim(
                    (string) (
                        $address['Area'] ?? ''
                    )
                ) .
                (
                    !empty($address['Region'])
                        ? ', ' . trim(
                            (string) $address['Region']
                        )
                        : ''
                );
            }

            $variants = array_values(
                array_filter(
                    array_unique($variants)
                )
            );

            throw new RuntimeException(
                'Неможливо однозначно визначити населений пункт "' .
                $deliveryCity .
                '". Варіанти: ' .
                implode(
                    '; ',
                    array_slice($variants, 0, 10)
                )
            );
        }

        /*
         * SettlementRef используется только для поиска улиц.
         */
        $settlementRef = trim(
            (string) ($selected['Ref'] ?? '')
        );

        /*
         * DeliveryCity используется как CityRef:
         *
         * - Counterparty/save;
         * - InternetDocument/save;
         * - CityRecipient.
         */
        $cityRef = trim(
            (string) ($selected['DeliveryCity'] ?? '')
        );

        if (!$this->isUuid($settlementRef)) {
            throw new RuntimeException(
                'Нова Пошта не повернула SettlementRef ' .
                'населеного пункту.'
            );
        }

        if (
            !$this->isUuid($cityRef) ||
            $cityRef === '00000000-0000-0000-0000-000000000000'
        ) {
            throw new RuntimeException(
                'Для населеного пункту "' .
                ($selected['MainDescription'] ?? $deliveryCity) .
                '" Нова Пошта не повернула доступний DeliveryCity. ' .
                'Адресна доставка може бути недоступна.'
            );
        }

        return [
            'settlement_ref' => $settlementRef,
            'city_ref' => $cityRef,

            'description' => trim(
                (string) (
                    $selected['MainDescription'] ?? ''
                )
            ),

            'area' => trim(
                (string) ($selected['Area'] ?? '')
            ),

            'region' => trim(
                (string) ($selected['Region'] ?? '')
            ),

            'type' => trim(
                (string) (
                    $selected['SettlementTypeCode'] ?? ''
                )
            ),
        ];
    }

    private function extractStreetTypeHint($value)
    {
        $value = $this->normalizeComparableText(
            $value
        );

        if ($value === '') {
            return '';
        }

        $patterns = [
            'набережна' => [
                '/(?:^|\s)наб\.?(?:\s|$)/u',
                '/(?:^|\s)набережна(?:\s|$)/u',
                '/(?:^|\s)набережная(?:\s|$)/u',
            ],

            'вулиця' => [
                '/(?:^|\s)вул\.?(?:\s|$)/u',
                '/(?:^|\s)вулиця(?:\s|$)/u',
                '/(?:^|\s)ул\.?(?:\s|$)/u',
                '/(?:^|\s)улица(?:\s|$)/u',
            ],

            'провулок' => [
                '/(?:^|\s)пров\.?(?:\s|$)/u',
                '/(?:^|\s)провулок(?:\s|$)/u',
                '/(?:^|\s)пер\.?(?:\s|$)/u',
                '/(?:^|\s)переулок(?:\s|$)/u',
            ],

            'проспект' => [
                '/(?:^|\s)просп\.?(?:\s|$)/u',
                '/(?:^|\s)проспект(?:\s|$)/u',
            ],

            'бульвар' => [
                '/(?:^|\s)бул\.?(?:\s|$)/u',
                '/(?:^|\s)бульвар(?:\s|$)/u',
            ],

            'площа' => [
                '/(?:^|\s)пл\.?(?:\s|$)/u',
                '/(?:^|\s)площа(?:\s|$)/u',
                '/(?:^|\s)площадь(?:\s|$)/u',
            ],

            'шосе' => [
                '/(?:^|\s)шосе(?:\s|$)/u',
                '/(?:^|\s)шоссе(?:\s|$)/u',
            ],

            'проїзд' => [
                '/(?:^|\s)проїзд(?:\s|$)/u',
                '/(?:^|\s)проезд(?:\s|$)/u',
            ],

            'узвіз' => [
                '/(?:^|\s)узвіз(?:\s|$)/u',
                '/(?:^|\s)спуск(?:\s|$)/u',
            ],

            'алея' => [
                '/(?:^|\s)алея(?:\s|$)/u',
                '/(?:^|\s)аллея(?:\s|$)/u',
            ],
        ];

        foreach ($patterns as $type => $typePatterns) {
            foreach ($typePatterns as $pattern) {
                if (preg_match($pattern, $value)) {
                    return $type;
                }
            }
        }

        return '';
    }

    private function streetMatchesType(
        array $street,
        $expectedType
    ) {
        $expectedType = trim(
            (string) $expectedType
        );

        if ($expectedType === '') {
            return false;
        }

        $candidateText = implode(
            ' ',
            [
                (string) (
                    $street['Description'] ?? ''
                ),

                (string) (
                    $street['DescriptionRu'] ?? ''
                ),

                (string) (
                    $street['StreetsTypeDescription']
                    ?? ''
                ),

                (string) (
                    $street['StreetsTypeDescriptionRu']
                    ?? ''
                ),
            ]
        );

        $candidateText =
            $this->normalizeComparableText(
                $candidateText
            );

        $aliases = [
            'набережна' => [
                'набережна',
                'набережная',
            ],

            'вулиця' => [
                'вулиця',
                'улица',
            ],

            'провулок' => [
                'провулок',
                'переулок',
            ],

            'проспект' => [
                'проспект',
            ],

            'бульвар' => [
                'бульвар',
            ],

            'площа' => [
                'площа',
                'площадь',
            ],

            'шосе' => [
                'шосе',
                'шоссе',
            ],

            'проїзд' => [
                'проїзд',
                'проезд',
            ],

            'узвіз' => [
                'узвіз',
                'спуск',
            ],

            'алея' => [
                'алея',
                'аллея',
            ],
        ];

        foreach (
            $aliases[$expectedType] ?? [$expectedType]
            as $alias
        ) {
            $alias = $this->normalizeComparableText(
                $alias
            );

            if (
                $alias !== ''
                && mb_strpos(
                    $candidateText,
                    $alias,
                    0,
                    'UTF-8'
                ) !== false
            ) {
                return true;
            }
        }

        return false;
    }

    private function resolveRecipientStreet(
        $cityRef,
        $rawStreet
    ) {
        $cityRef = trim((string) $cityRef);
        $rawStreet = trim((string) $rawStreet);

        if (!$this->isUuid($cityRef)) {
            throw new RuntimeException(
                'Не передано коректний CityRef для пошуку вулиці.'
            );
        }

        if ($rawStreet === '') {
            throw new RuntimeException(
                'Не вказано назву вулиці.'
            );
        }

        /*
         * Сохраняем тип улицы до удаления сокращений.
         *
         * Например:
         * Автострадна наб. -> набережна
         * Миру вул.        -> вулиця
         * Шевченка пров.   -> провулок
         */
        $streetTypeHint = $this->extractStreetTypeHint(
            $rawStreet
        );

        /*
         * Текст только для поиска названия.
         *
         * Автострадна наб. -> Автострадна
         */
        $search = $this->normalizeStreetSearch(
            $rawStreet
        );

        if ($search === '') {
            throw new RuntimeException(
                'Не вдалося визначити назву вулиці з адреси: "' .
                $rawStreet .
                '".'
            );
        }

        $streets = $this->client->call(
            'Address',
            'getStreet',
            [
                'CityRef' => $cityRef,
                'FindByString' => $search,
                'Page' => '1',
            ]
        );

        if (empty($streets)) {
            throw new RuntimeException(
                'Нова Пошта не знайшла вулицю "' .
                $rawStreet .
                '" у вибраному населеному пункті. ' .
                'Пошуковий текст: "' .
                $search .
                '".'
            );
        }

        $normalizedSearch =
            $this->normalizeComparableText($search);

        /*
         * Сначала выбираем кандидатов с совпадающим
         * базовым названием улицы.
         */
        $nameMatches = [];

        foreach ($streets as $street) {
            if (!is_array($street)) {
                continue;
            }

            $description = trim(
                (string) ($street['Description'] ?? '')
            );

            $descriptionRu = trim(
                (string) ($street['DescriptionRu'] ?? '')
            );

            $normalizedDescription =
                $this->normalizeComparableText(
                    $this->normalizeStreetSearch(
                        $description
                    )
                );

            $normalizedDescriptionRu =
                $this->normalizeComparableText(
                    $this->normalizeStreetSearch(
                        $descriptionRu
                    )
                );

            if (
                $normalizedDescription === $normalizedSearch
                ||
                $normalizedDescriptionRu === $normalizedSearch
            ) {
                $nameMatches[] = $street;
            }
        }

        /*
         * Если совпадений по точному базовому названию нет,
         * но API вернул один результат, можем использовать его.
         */
        if (empty($nameMatches) && count($streets) === 1) {
            $nameMatches = [
                $streets[0],
            ];
        }

        if (empty($nameMatches)) {
            throw new RuntimeException(
                'Нова Пошта не знайшла точного відповідника для вулиці "' .
                $rawStreet .
                '".'
            );
        }

        /*
         * Если найден один вариант — всё однозначно.
         */
        if (count($nameMatches) === 1) {
            $selected = $nameMatches[0];
        } else {
            /*
             * При нескольких совпадениях используем тип улицы.
             *
             * Например:
             * Автострадна наб.
             *
             * Автострадна           — не подходит;
             * Автострадна Набережна — подходит.
             */
            $typeMatches = [];

            if ($streetTypeHint !== '') {
                foreach ($nameMatches as $street) {
                    if (
                        $this->streetMatchesType(
                            $street,
                            $streetTypeHint
                        )
                    ) {
                        $typeMatches[] = $street;
                    }
                }
            }

            if (count($typeMatches) === 1) {
                $selected = $typeMatches[0];
            } else {
                $variants = [];

                foreach ($nameMatches as $street) {
                    if (!is_array($street)) {
                        continue;
                    }

                    $description = trim(
                        (string) (
                            $street['Description'] ?? ''
                        )
                    );

                    $streetType = trim(
                        (string) (
                            $street['StreetsTypeDescription']
                            ?? ''
                        )
                    );

                    $ref = trim(
                        (string) (
                            $street['Ref'] ?? ''
                        )
                    );

                    $label = $description;

                    if (
                        $streetType !== ''
                        && mb_stripos(
                            $description,
                            $streetType,
                            0,
                            'UTF-8'
                        ) === false
                    ) {
                        $label .= ' (' . $streetType . ')';
                    }

                    if ($ref !== '') {
                        $label .= ' [' . $ref . ']';
                    }

                    if ($label !== '') {
                        $variants[] = $label;
                    }
                }

                $variants = array_values(
                    array_unique($variants)
                );

                $typeMessage = $streetTypeHint !== ''
                    ? ' Визначений тип: "' .
                        $streetTypeHint .
                        '".'
                    : '';

                throw new RuntimeException(
                    'Неможливо однозначно визначити вулицю "' .
                    $rawStreet .
                    '".' .
                    $typeMessage .
                    ' Знайдено варіанти: ' .
                    implode(
                        '; ',
                        array_slice($variants, 0, 10)
                    )
                );
            }
        }

        $streetRef = trim(
            (string) ($selected['Ref'] ?? '')
        );

        if (!$this->isUuid($streetRef)) {
            throw new RuntimeException(
                'Нова Пошта не повернула коректний StreetRef.'
            );
        }

        $description = trim(
            (string) (
                $selected['Description'] ?? ''
            )
        );

        if ($description === '') {
            throw new RuntimeException(
                'Нова Пошта повернула вулицю без назви.'
            );
        }

        return [
            'ref' => $streetRef,

            'description' => $description,

            'type' => trim(
                (string) (
                    $selected['StreetsTypeDescription']
                    ?? ''
                )
            ),
        ];
    }

    private function normalizeSettlementSearch($value)
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        $value = preg_replace(
            '/\s+/u',
            ' ',
            $value
        );

        /*
         * Житомир г. (Житомирская область)
         * Кропивницький (Кіровоград)
         */
        $value = preg_replace(
            '/\s*\([^)]*\)\s*$/u',
            '',
            $value
        );

        $value = preg_replace(
            '/\s+(?:м\.?|г\.?|с\.?|смт)\s*$/ui',
            '',
            $value
        );

        return trim($value);
    }

    private function normalizeStreetSearch($value)
    {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        /*
         * Нормализуем пробелы.
         */
        $value = preg_replace(
            '/\s+/u',
            ' ',
            $value
        );

        /*
         * Перечень обозначений типа улицы.
         */
        $streetTypes = implode('|', [
            'вул\.?',
            'вулиця',
            'ул\.?',
            'улица',

            'просп\.?',
            'проспект',

            'пров\.?',
            'провулок',
            'пер\.?',
            'переулок',

            'наб\.?',
            'набережна',
            'набережная',

            'бул\.?',
            'бульвар',

            'пл\.?',
            'площа',
            'площадь',

            'шосе',
            'шоссе',

            'проїзд',
            'проезд',

            'узвіз',
            'спуск',

            'алея',
            'аллея',

            'тупик',
            'туп\.?',

            'дорога',
        ]);

        /*
         * Удаляем тип улицы в начале:
         *
         * вул. Богдана Хмельницького
         * просп. Перемоги
         * пров. Шевченка
         */
        $value = preg_replace(
            '/^(?:' . $streetTypes . ')\s+/ui',
            '',
            $value
        );

        /*
         * Удаляем тип улицы в конце:
         *
         * Богдана Хмельницького вул.
         * Автострадна наб.
         * Шевченка пров.
         */
        $value = preg_replace(
            '/\s+(?:' . $streetTypes . ')$/ui',
            '',
            $value
        );

        /*
         * Иногда сокращение отделено запятой.
         */
        $value = preg_replace(
            '/^(?:' . $streetTypes . ')\s*,\s*/ui',
            '',
            $value
        );

        $value = preg_replace(
            '/\s*,\s*(?:' . $streetTypes . ')$/ui',
            '',
            $value
        );

        return trim(
            $value,
            " \t\n\r\0\x0B,."
        );
    }

    private function normalizeComparableText($value)
    {
        $value = mb_strtolower(
            trim((string) $value),
            'UTF-8'
        );

        $value = str_replace(
            [
                '’',
                '`',
                'ʼ',
            ],
            "'",
            $value
        );

        $value = preg_replace(
            '/\s+/u',
            ' ',
            $value
        );

        return trim($value);
    }

    private function validateOrder(Order $order)
    {
        if ($order->status === 'canceled') {
            throw new RuntimeException(
                'Скасоване замовлення не можна відправити.'
            );
        }

        if (trim((string) $order->tracking_number) !== '') {
            throw new RuntimeException(
                'Замовлення вже має номер ТТН: ' .
                $order->tracking_number
            );
        }

        if ($order->items->isEmpty()) {
            throw new RuntimeException(
                'У замовленні немає товарів.'
            );
        }

        if (trim((string) $order->customer_name) === '') {
            throw new RuntimeException(
                'Не вказано ПІБ одержувача.'
            );
        }

        if (trim((string) $order->delivery_phone) === '') {
            throw new RuntimeException(
                'Не вказано телефон одержувача.'
            );
        }

        $deliveryAddressId = trim(
            (string) $order->delivery_address_id
        );

        /*
         * Для адресной доставки заранее проверяем,
         * что город, улицу и дом можно разобрать.
         */
        if (!$this->isUuid($deliveryAddressId)) {
            $this->parseRecipientAddress($order);
        }

        $this->validateSenderConfiguration();
    }

    private function parseRecipientAddress(Order $order)
    {
        $city = $this->normalizeRecipientCity(
            $order->delivery_city
        );

        $rawAddress = trim(
            (string) $order->delivery_street
        );

        if ($city === '') {
            throw new RuntimeException(
                'Не вказано населений пункт для адресної доставки.'
            );
        }

        if ($rawAddress === '') {
            throw new RuntimeException(
                'Не вказано адресу одержувача.'
            );
        }

        $rawAddress = preg_replace(
            '/\s+/u',
            ' ',
            $rawAddress
        );

        /*
         * Основной формат Rozetka:
         *
         * Вулиця, буд.12, кв.34
         */
        $matched = preg_match(
            '/^(.+?),\s*' .
            '(?:буд\.?|будинок|дом\.?|д\.)\s*' .
            '([^,]+)' .
            '(?:,\s*(?:кв\.?|квартира)\s*(.*))?' .
            '$/ui',
            $rawAddress,
            $matches
        );

        /*
         * Дополнительный формат:
         *
         * Вулиця, 12, кв.34
         */
        if (!$matched) {
            $matched = preg_match(
                '/^(.+?),\s*' .
                '([0-9][^,]*)' .
                '(?:,\s*(?:кв\.?|квартира)\s*(.*))?' .
                '$/ui',
                $rawAddress,
                $matches
            );
        }

        if (!$matched) {
            throw new RuntimeException(
                'Не вдалося розібрати адресу: "' .
                $rawAddress .
                '". Очікується формат: ' .
                '"Назва вулиці, буд.12, кв.34".'
            );
        }

        $street = trim(
            (string) ($matches[1] ?? '')
        );

        $house = trim(
            (string) ($matches[2] ?? '')
        );

        $flat = trim(
            (string) ($matches[3] ?? '')
        );

        $street = trim($street, " \t\n\r\0\x0B,");
        $house = trim($house, " \t\n\r\0\x0B,");
        $flat = trim($flat, " \t\n\r\0\x0B,");

        /*
         * В источнике иногда записано просто "кв."
         * без номера — это допустимо.
         */
        if (in_array(
            mb_strtolower($flat),
            ['-', '—', 'немає', 'нет'],
            true
        )) {
            $flat = '';
        }

        if ($street === '') {
            throw new RuntimeException(
                'Не вдалося визначити назву вулиці.'
            );
        }

        if ($house === '') {
            throw new RuntimeException(
                'Не вдалося визначити номер будинку.'
            );
        }

        return [
            'city' => $city,
            'street' => $street,
            'house' => $house,
            'flat' => $flat,
        ];
    }

    private function normalizeRecipientCity($value)
    {
        $city = trim((string) $value);

        if ($city === '') {
            return '';
        }

        $city = preg_replace(
            '/\s+/u',
            ' ',
            $city
        );

        /*
         * Примеры:
         *
         * Житомир г. (Житомирская область)
         * Абазовка с. (Полтавский р-н, Полтавская область)
         * Кучаків (Кірове)
         */
        $city = preg_replace(
            '/\s*\([^)]*\)\s*$/u',
            '',
            $city
        );

        $city = preg_replace(
            '/\s+(?:м\.?|г\.?|с\.?|смт|місто|село)\s*$/ui',
            '',
            $city
        );

        return trim(
            $city,
            " \t\n\r\0\x0B,"
        );
    }

    private function validateSenderConfiguration()
    {
        $required = [
            'sender_ref' =>
                'NOVA_POSHTA_SENDER_REF',

            'contact_sender_ref' =>
                'NOVA_POSHTA_CONTACT_SENDER_REF',

            'sender_address_ref' =>
                'NOVA_POSHTA_SENDER_ADDRESS_REF',

            'sender_city_ref' =>
                'NOVA_POSHTA_SENDER_CITY_REF',

            'sender_phone' =>
                'NOVA_POSHTA_SENDER_PHONE',
        ];

        foreach ($required as $configKey => $envName) {
            $value = trim(
                (string) config(
                    'services.nova_poshta.' . $configKey,
                    ''
                )
            );

            if ($value === '') {
                throw new RuntimeException(
                    'Не задано ' . $envName . ' у файлі .env.'
                );
            }
        }
    }

    /**
     * Получить отделение по его Ref.
     */
    private function resolveRecipientWarehouse($warehouseRef)
    {
        $warehouseRef = trim((string) $warehouseRef);

        $warehouses = $this->client->call(
            'Address',
            'getWarehouses',
            [
                'Ref' => $warehouseRef,
                'Page' => '1',
                'Limit' => '10',
            ]
        );

        foreach ($warehouses as $warehouse) {
            if (
                is_array($warehouse) &&
                isset($warehouse['Ref']) &&
                strtolower($warehouse['Ref']) ===
                    strtolower($warehouseRef)
            ) {
                return $warehouse;
            }
        }

        throw new RuntimeException(
            'Відділення Нової Пошти не знайдено за Ref: ' .
            $warehouseRef
        );
    }

    private function extractCityRef(array $warehouse)
    {
        foreach ([
            'CityRef',
            'SettlementRef',
        ] as $key) {
            $value = trim(
                (string) ($warehouse[$key] ?? '')
            );

            if ($this->isUuid($value)) {
                return $value;
            }
        }

        throw new RuntimeException(
            'API не повернув Ref населеного пункту відділення.'
        );
    }

    /**
     * Создать получателя и получить Ref контактного лица.
     */
    private function createRecipient(
        $cityRef,
        array $name,
        $phone
    ) {
        $result = $this->client->call(
            'Counterparty',
            'save',
            [
                'FirstName' =>
                    $name['first_name'],

                'MiddleName' =>
                    $name['middle_name'],

                'LastName' =>
                    $name['last_name'],

                'Phone' => $phone,

                'Email' => '',

                'CounterpartyType' =>
                    'PrivatePerson',

                'CounterpartyProperty' =>
                    'Recipient',

                'CityRef' => $cityRef,
            ]
        );

        if (empty($result[0]) || !is_array($result[0])) {
            throw new RuntimeException(
                'API не повернув дані створеного одержувача.'
            );
        }

        $recipientData = $result[0];

        $recipientRef = trim(
            (string) ($recipientData['Ref'] ?? '')
        );

        if (!$this->isUuid($recipientRef)) {
            throw new RuntimeException(
                'API не повернув коректний Ref одержувача.'
            );
        }

        /*
         * В разных ответах API контактное лицо может быть
         * вложено в результат создания получателя.
         */
        $contactRef = trim(
            (string) data_get(
                $recipientData,
                'ContactPerson.data.0.Ref',
                ''
            )
        );

        if (!$this->isUuid($contactRef)) {
            $contactRef = trim(
                (string) data_get(
                    $recipientData,
                    'ContactPerson.0.Ref',
                    ''
                )
            );
        }

        /*
         * Если Ref контакта не пришёл — отдельно получаем
         * контактных лиц контрагента.
         */
        if (!$this->isUuid($contactRef)) {
            $contacts = $this->client->call(
                'Counterparty',
                'getCounterpartyContactPersons',
                [
                    'Ref' => $recipientRef,
                    'Page' => '1',
                ]
            );

            $contactRef = $this->findContactRef(
                $contacts,
                $phone
            );
        }

        if (!$this->isUuid($contactRef)) {
            throw new RuntimeException(
                'Не вдалося отримати Ref контактної особи одержувача.'
            );
        }

        return [
            'recipient_ref' => $recipientRef,
            'contact_ref' => $contactRef,
        ];
    }

    private function findContactRef(
        array $contacts,
        $expectedPhone
    ) {
        /*
         * Сначала ищем контакт с таким же телефоном.
         */
        foreach ($contacts as $contact) {
            if (!is_array($contact)) {
                continue;
            }

            $phone = isset($contact['Phones'])
                ? $contact['Phones']
                : ($contact['Phone'] ?? '');

            if (
                $this->normalizePhoneSoft($phone) ===
                $expectedPhone
            ) {
                $ref = trim(
                    (string) ($contact['Ref'] ?? '')
                );

                if ($this->isUuid($ref)) {
                    return $ref;
                }
            }
        }

        /*
         * Если совпадение по телефону не найдено,
         * используем первый корректный контакт.
         */
        foreach ($contacts as $contact) {
            if (!is_array($contact)) {
                continue;
            }

            $ref = trim(
                (string) ($contact['Ref'] ?? '')
            );

            if ($this->isUuid($ref)) {
                return $ref;
            }
        }

        return '';
    }

    /**
     * Собрать места отправления с учётом количества товаров.
     */
    private function buildCargo(Order $order)
    {
        $optionsSeat = [];

        $totalWeight = 0.0;
        $volumeGeneral = 0.0;

        foreach ($order->items as $item) {
            $quantity = (int) $item->quantity;

            if ($quantity < 1) {
                throw new RuntimeException(
                    'Товар ' . $item->rz_code .
                    ' має некоректну кількість.'
                );
            }

            $dimension = $this->findProductDimension(
                $item->rz_code
            );

            $dimension->loadMissing('places');

            $places = $dimension->places;

            /*
             * Если отдельные места не заведены,
             * используем сводные размеры товара как одно место.
             */
            if ($places->isEmpty()) {
                $places = collect([
                    (object) [
                        'weight' => $dimension->weight,
                        'length' => $dimension->length,
                        'width' => $dimension->width,
                        'height' => $dimension->height,
                    ],
                ]);
            }

            /*
             * При quantity = 2 набор мест повторяется дважды.
             */
            for ($unit = 1; $unit <= $quantity; $unit++) {
                foreach ($places as $place) {
                    $seat = $this->makeSeat(
                        $place,
                        $item->rz_code
                    );

                    $optionsSeat[] = $seat;

                    $totalWeight += (float) $seat['weight'];

                    $volumeGeneral +=
                        (float) $seat['volumetricVolume'];
                }
            }
        }

        if (empty($optionsSeat)) {
            throw new RuntimeException(
                'Не вдалося сформувати жодного вантажного місця.'
            );
        }

        if ($totalWeight <= 0) {
            throw new RuntimeException(
                'Загальна вага замовлення повинна бути більшою за нуль.'
            );
        }

        return [
            'options_seat' => $optionsSeat,

            'seats_amount' => count($optionsSeat),

            'total_weight' =>
                round($totalWeight, 3),

            'volume_general' =>
                round($volumeGeneral, 6),
        ];
    }

    private function makeSeat($place, $rzCode)
    {
        $weight = (float) $place->weight;
        $length = (float) $place->length;
        $width = (float) $place->width;
        $height = (float) $place->height;

        if (
            $weight <= 0 ||
            $length <= 0 ||
            $width <= 0 ||
            $height <= 0
        ) {
            throw new RuntimeException(
                'Для товару ' . $rzCode .
                ' не заповнено вагу або габарити місця.'
            );
        }

        /*
         * Размеры хранятся в сантиметрах.
         * Получаем объём в кубических метрах.
         */
        $volume = (
            $length *
            $width *
            $height
        ) / 1000000;

        return [
            'weight' => round($weight, 3),

            'volumetricLength' =>
                round($length, 1),

            'volumetricWidth' =>
                round($width, 1),

            'volumetricHeight' =>
                round($height, 1),

            'volumetricVolume' =>
                round($volume, 6),
        ];
    }

    private function findProductDimension($rzCode)
    {
        $originalCode = trim((string) $rzCode);
        $normalizedCode = $this->normalizeProductCode(
            $originalCode
        );

        if (isset($this->dimensionCache[$normalizedCode])) {
            return $this->dimensionCache[$normalizedCode];
        }

        /*
         * Сначала ищем по исходному значению.
         */
        $dimension = ProductDimension::query()
            ->with('places')
            ->where('rz_code', $originalCode)
            ->first();

        /*
         * Повторный поиск после замены неразрывных пробелов.
         */
        if (!$dimension && $normalizedCode !== $originalCode) {
            $dimension = ProductDimension::query()
                ->with('places')
                ->where('rz_code', $normalizedCode)
                ->first();
        }

        if (!$dimension) {
            throw new RuntimeException(
                'Для товару з кодом ' . $originalCode .
                ' не знайдено вагу та габарити.'
            );
        }

        $this->dimensionCache[$normalizedCode] = $dimension;

        return $dimension;
    }

    private function buildDocumentProperties(
        Order $order,
        array $recipientContext,
        $recipientPhone,
        array $cargo
    ) {
        $senderLocationType = trim(
            (string) config(
                'services.nova_poshta.sender_location_type',
                'Warehouse'
            )
        );

        if (!in_array(
            $senderLocationType,
            ['Doors', 'Warehouse'],
            true
        )) {
            throw new RuntimeException(
                'NOVA_POSHTA_SENDER_LOCATION_TYPE повинен бути ' .
                'Doors або Warehouse.'
            );
        }

        $recipientType = trim(
            (string) (
                $recipientContext['type'] ?? ''
            )
        );

        if (!in_array(
            $recipientType,
            ['warehouse', 'doors'],
            true
        )) {
            throw new RuntimeException(
                'Не вдалося визначити тип доставки одержувачу.'
            );
        }

        $recipientLocationType =
            $recipientType === 'warehouse'
                ? 'Warehouse'
                : 'Doors';

        /*
         * При текущем отправителе из отделения:
         *
         * WarehouseWarehouse — получение в отделении;
         * WarehouseDoors     — доставка по адресу.
         */
        $serviceType =
            $senderLocationType .
            $recipientLocationType;

        $declaredCost = $this->calculateDeclaredCost(
            $order
        );

        /*
         * =========================================================
         * ОБЩИЕ ДАННЫЕ ТТН
         * =========================================================
         */
        $properties = [
            'DateTime' =>
                now()->format('d.m.Y'),

            'PayerType' => config(
                'services.nova_poshta.payer_type',
                'Sender'
            ),

            'PaymentMethod' => config(
                'services.nova_poshta.payment_method',
                'Cash'
            ),

            'CargoType' => config(
                'services.nova_poshta.cargo_type',
                'Cargo'
            ),

            'Weight' =>
                $cargo['total_weight'],

            'VolumeGeneral' =>
                $cargo['volume_general'],

            'ServiceType' =>
                $serviceType,

            'SeatsAmount' =>
                $cargo['seats_amount'],

            'Description' =>
                $this->makeDescription($order),

            'Cost' =>
                $declaredCost,

            /*
             * Отправитель.
             */
            'CitySender' => config(
                'services.nova_poshta.sender_city_ref'
            ),

            'Sender' => config(
                'services.nova_poshta.sender_ref'
            ),

            'SenderAddress' => config(
                'services.nova_poshta.sender_address_ref'
            ),

            'ContactSender' => config(
                'services.nova_poshta.contact_sender_ref'
            ),

            'SendersPhone' => $this->normalizePhone(
                config('services.nova_poshta.sender_phone')
            ),

            /*
             * Получатель.
             */
            'RecipientsPhone' =>
                $recipientPhone,

            /*
             * Грузовые места.
             */
            'OptionsSeat' =>
                $cargo['options_seat'],
        ];

        /*
         * Внутренний номер заказа Rozetka.
         */
        $clientBarcode = trim(
            (string) $order->comment
        );

        if ($clientBarcode !== '') {
            $properties['InfoRegClientBarcodes'] =
                mb_substr(
                    $clientBarcode,
                    0,
                    100
                );
        }

        /*
         * =========================================================
         * 1. ПОЛУЧЕНИЕ В ОТДЕЛЕНИИ
         * =========================================================
         */
        if ($recipientType === 'warehouse') {
            $cityRef = trim(
                (string) (
                    $recipientContext['city_ref'] ?? ''
                )
            );

            $warehouseRef = trim(
                (string) (
                    $recipientContext['warehouse_ref'] ?? ''
                )
            );

            $recipientRef = trim(
                (string) (
                    $recipientContext['recipient_ref'] ?? ''
                )
            );

            $contactRef = trim(
                (string) (
                    $recipientContext['contact_ref'] ?? ''
                )
            );

            if (!$this->isUuid($cityRef)) {
                throw new RuntimeException(
                    'Не визначено CityRecipient для доставки у відділення.'
                );
            }

            if (!$this->isUuid($warehouseRef)) {
                throw new RuntimeException(
                    'Не визначено Ref відділення одержувача.'
                );
            }

            if (!$this->isUuid($recipientRef)) {
                throw new RuntimeException(
                    'Не визначено Ref одержувача.'
                );
            }

            if (!$this->isUuid($contactRef)) {
                throw new RuntimeException(
                    'Не визначено Ref контактної особи одержувача.'
                );
            }

            $properties = array_merge(
                $properties,
                [
                    'CityRecipient' =>
                        $cityRef,

                    'Recipient' =>
                        $recipientRef,

                    'RecipientAddress' =>
                        $warehouseRef,

                    'ContactRecipient' =>
                        $contactRef,
                ]
            );
        } else {
            /*
             * =====================================================
             * 2. АДРЕСНАЯ ДОСТАВКА
             * =====================================================
             */

            $addressMode = trim(
                (string) (
                    $recipientContext['address_mode']
                    ?? 'counterparty'
                )
            );

            /*
             * =====================================================
             * 2.1. НОВЫЙ СПРАВОЧНИК:
             * SettlementRef + SettlementStreetRef
             * =====================================================
             */
            if ($addressMode === 'settlement') {
                $settlementRef = trim(
                    (string) (
                        $recipientContext['settlement_ref']
                        ?? ''
                    )
                );

                $streetRef = trim(
                    (string) (
                        $recipientContext['street_ref']
                        ?? ''
                    )
                );

                $recipientName = trim(
                    (string) (
                        $recipientContext['recipient_name']
                        ?? $order->customer_name
                    )
                );

                $house = trim(
                    (string) (
                        $recipientContext['house'] ?? ''
                    )
                );

                $flat = trim(
                    (string) (
                        $recipientContext['flat'] ?? ''
                    )
                );

                if (!$this->isUuid($settlementRef)) {
                    throw new RuntimeException(
                        'Не визначено SettlementRef одержувача.'
                    );
                }

                if (!$this->isUuid($streetRef)) {
                    throw new RuntimeException(
                        'Не визначено SettlementStreetRef одержувача.'
                    );
                }

                if ($recipientName === '') {
                    throw new RuntimeException(
                        'Не вказано ПІБ одержувача.'
                    );
                }

                if ($house === '') {
                    throw new RuntimeException(
                        'Не вказано номер будинку одержувача.'
                    );
                }

                /*
                 * Новый адрес создаётся непосредственно
                 * во время InternetDocument/save.
                 */
                $properties = array_merge(
                    $properties,
                    [
                        'NewAddress' => '1',

                        'RecipientCityRef' =>
                            $settlementRef,

                        'RecipientStreetRef' =>
                            $streetRef,

                        'RecipientHouse' =>
                            $house,

                        'RecipientFlat' =>
                            $flat,

                        'RecipientType' =>
                            'PrivatePerson',

                        'RecipientName' =>
                            $recipientName,

                        'RecipientContactName' =>
                            $recipientName,
                    ]
                );
            } elseif ($addressMode === 'counterparty') {
                /*
                 * =================================================
                 * 2.2. СТАРЫЙ СПРАВОЧНИК:
                 * Counterparty + Address/save
                 * =================================================
                 */

                $cityRef = trim(
                    (string) (
                        $recipientContext['city_ref'] ?? ''
                    )
                );

                $recipientRef = trim(
                    (string) (
                        $recipientContext['recipient_ref'] ?? ''
                    )
                );

                $contactRef = trim(
                    (string) (
                        $recipientContext['contact_ref'] ?? ''
                    )
                );

                $addressRef = trim(
                    (string) (
                        $recipientContext['address_ref'] ?? ''
                    )
                );

                if (!$this->isUuid($cityRef)) {
                    throw new RuntimeException(
                        'Не визначено CityRecipient ' .
                        'для адресної доставки.'
                    );
                }

                if (!$this->isUuid($recipientRef)) {
                    throw new RuntimeException(
                        'Не визначено Ref одержувача ' .
                        'для адресної доставки.'
                    );
                }

                if (!$this->isUuid($contactRef)) {
                    throw new RuntimeException(
                        'Не визначено Ref контактної особи ' .
                        'для адресної доставки.'
                    );
                }

                if (!$this->isUuid($addressRef)) {
                    throw new RuntimeException(
                        'Не визначено Ref адреси одержувача.'
                    );
                }

                $properties = array_merge(
                    $properties,
                    [
                        'CityRecipient' =>
                            $cityRef,

                        'Recipient' =>
                            $recipientRef,

                        'RecipientAddress' =>
                            $addressRef,

                        'ContactRecipient' =>
                            $contactRef,
                    ]
                );
            } else {
                throw new RuntimeException(
                    'Невідомий режим адресної доставки: ' .
                    $addressMode
                );
            }
        }

        /*
         * =========================================================
         * НАЛОЖЕННЫЙ ПЛАТЁЖ
         * =========================================================
         */
        $cashOnDelivery = round(
            (float) $order->cash_on_delivery,
            2
        );

        if ($cashOnDelivery > 0) {
            $properties['BackwardDeliveryData'] = [
                [
                    'PayerType' =>
                        'Recipient',

                    'CargoType' =>
                        'Money',

                    'RedeliveryString' =>
                        $cashOnDelivery,
                ],
            ];
        }

        return $properties;
    }

    private function calculateDeclaredCost(Order $order)
    {
        $cost = $order->items->sum(function ($item) {
            return
                (float) $item->price *
                (int) $item->quantity;
        });

        if ($cost <= 0) {
            throw new RuntimeException(
                'Не вдалося визначити оголошену вартість замовлення.'
            );
        }

        return round($cost, 2);
    }

    private function makeDescription(Order $order)
    {
        $codes = $order->items
            ->pluck('supplier_code')
            ->filter()
            ->unique()
            ->implode(', ');

        $description = 'Сантехніка';

        if ($codes !== '') {
            $description .= ': ' . $codes;
        }

        if (trim((string) $order->comment) !== '') {
            $description .=
                ', замовлення ' .
                trim((string) $order->comment);
        }

        return mb_substr($description, 0, 100);
    }

    private function parseCustomerName($fullName)
    {
        $parts = preg_split(
            '/\s+/u',
            trim((string) $fullName),
            -1,
            PREG_SPLIT_NO_EMPTY
        );

        if (count($parts) < 2) {
            throw new RuntimeException(
                'ПІБ одержувача повинно містити щонайменше ' .
                'прізвище та ім’я.'
            );
        }

        return [
            /*
             * В текущих реальных заказах обычно:
             * Прізвище Ім’я По батькові.
             */
            'last_name' => $parts[0],
            'first_name' => $parts[1],

            'middle_name' => count($parts) > 2
                ? implode(
                    ' ',
                    array_slice($parts, 2)
                )
                : '',
        ];
    }

    private function normalizePhone($phone)
    {
        $digits = preg_replace(
            '/\D+/',
            '',
            (string) $phone
        );

        if (
            strlen($digits) === 10 &&
            strpos($digits, '0') === 0
        ) {
            $digits = '38' . $digits;
        }

        if (
            strlen($digits) === 11 &&
            strpos($digits, '80') === 0
        ) {
            $digits = '3' . $digits;
        }

        if (!preg_match('/^380\d{9}$/', $digits)) {
            throw new RuntimeException(
                'Некоректний номер телефону: ' . $phone
            );
        }

        return $digits;
    }

    private function normalizePhoneSoft($phone)
    {
        try {
            return $this->normalizePhone($phone);
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function normalizeProductCode($value)
    {
        $value = str_replace(
            "\xC2\xA0",
            ' ',
            trim((string) $value)
        );

        return preg_replace(
            '/[ \t]+/u',
            ' ',
            $value
        );
    }

    private function isUuid($value)
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-' .
            '[0-9a-f]{4}-[0-9a-f]{12}$/i',
            trim((string) $value)
        ) === 1;
    }

    /**
     * Удалить созданную ТТН в Новой Почте
     * и очистить связанные локальные данные.
     *
     * @return array
     */
    public function deleteForOrder(Order $order)
    {
        $order->refresh();

        $documentRef = trim(
            (string) $order->np_document_ref
        );

        $trackingNumber = trim(
            (string) $order->tracking_number
        );

        if (!$this->isUuid($documentRef)) {
            throw new RuntimeException(
                'У замовленні відсутній коректний Ref документа Нової Пошти.'
            );
        }

        /*
         * Сначала удаляем реальный документ в Новой Почте.
         *
         * Локальные данные до успешного ответа не очищаем.
         */
        $this->client->call(
            'InternetDocument',
            'delete',
            [
                'DocumentRefs' => [
                    $documentRef,
                ],
            ]
        );

        /*
         * После успешного ответа синхронизируем базу.
         */
        DB::transaction(function () use (
            $order,
            $documentRef,
            $trackingNumber
        ) {
            $lockedOrder = Order::query()
                ->lockForUpdate()
                ->findOrFail($order->id);

            $currentDocumentRef = trim(
                (string) $lockedOrder->np_document_ref
            );

            /*
             * Защита от ситуации, когда между API-запросом
             * и обновлением базы у заказа появилась другая ТТН.
             */
            if (
                strtolower($currentDocumentRef) !==
                strtolower($documentRef)
            ) {
                throw new RuntimeException(
                    'Ref документа у замовленні змінився під час видалення.'
                );
            }

            $entriesQuery = TtnBatchOrder::query()
                ->where('order_id', $lockedOrder->id)
                ->where('status', 'success');

            if ($trackingNumber !== '') {
                $entriesQuery->where(
                    'tracking_number',
                    $trackingNumber
                );
            }

            $entries = $entriesQuery
                ->lockForUpdate()
                ->get();

            $batchIds = $entries
                ->pluck('ttn_batch_id')
                ->filter()
                ->unique()
                ->values();

            /*
             * Возвращаем заказ в состояние до отправки.
             */
            $lockedOrder->forceFill([
                'tracking_number' => null,
                'ttn_created_at' => null,
                'status' => 'created',

                'np_service_type' => null,

                /*
                 * Для доставки в отделение этот Ref относится
                 * к конкретной удалённой ТТН, поэтому очищаем.
                 */
                'np_warehouse_ref' => null,

                /*
                 * Эти значения создаются для конкретного
                 * получателя и конкретной накладной.
                 */
                'np_recipient_ref' => null,
                'np_recipient_contact_ref' => null,
                'np_recipient_address_ref' => null,

                'np_document_ref' => null,
            ])->save();

            /*
             * В пакете запись снова становится pending.
             * После этого её можно повторно обработать.
             */
            if ($entries->isNotEmpty()) {
                TtnBatchOrder::query()
                    ->whereIn(
                        'id',
                        $entries->pluck('id')->all()
                    )
                    ->update([
                        'status' => 'pending',
                        'tracking_number' => null,
                        'error_message' => null,

                        'processed_at' => null,
                        'last_attempt_at' => null,

                        'updated_at' => now(),
                    ]);
            }

            foreach ($batchIds as $batchId) {
                $this->refreshBatchAfterDeletion(
                    (int) $batchId
                );
            }
        });

        return [
            'tracking_number' => $trackingNumber,
            'document_ref' => $documentRef,
        ];
    }

    private function refreshBatchAfterDeletion($batchId)
    {
        $batch = TtnBatch::query()
            ->lockForUpdate()
            ->find($batchId);

        if (!$batch) {
            return;
        }

        $counts = TtnBatchOrder::query()
            ->where('ttn_batch_id', $batchId)

            ->selectRaw('COUNT(*) AS total_count')

            ->selectRaw(
                "SUM(CASE WHEN status = 'pending' " .
                "THEN 1 ELSE 0 END) AS pending_count"
            )

            ->selectRaw(
                "SUM(CASE WHEN status = 'processing' " .
                "THEN 1 ELSE 0 END) AS processing_count"
            )

            ->selectRaw(
                "SUM(CASE WHEN status = 'success' " .
                "THEN 1 ELSE 0 END) AS success_count"
            )

            ->selectRaw(
                "SUM(CASE WHEN status = 'failed' " .
                "THEN 1 ELSE 0 END) AS failed_count"
            )

            ->first();

        $totalCount = (int) $counts->total_count;
        $pendingCount = (int) $counts->pending_count;
        $processingCount = (int) $counts->processing_count;
        $successCount = (int) $counts->success_count;
        $failedCount = (int) $counts->failed_count;

        $status = 'pending';
        $startedAt = $batch->started_at;
        $completedAt = null;

        if ($processingCount > 0) {
            $status = 'processing';
        } elseif ($pendingCount > 0) {
            $status = 'pending';
        } elseif (
            $successCount > 0 &&
            $failedCount === 0
        ) {
            $status = 'completed';
            $completedAt = now();
        } elseif ($successCount > 0) {
            $status = 'partial';
            $completedAt = now();
        } elseif ($failedCount > 0) {
            $status = 'failed';
            $completedAt = now();
        }

        /*
         * Все созданные ТТН были удалены,
         * пакет полностью возвращён к началу.
         */
        if (
            $pendingCount === $totalCount &&
            $processingCount === 0 &&
            $successCount === 0 &&
            $failedCount === 0
        ) {
            $startedAt = null;
            $completedAt = null;
        }

        $batch->update([
            'status' => $status,

            'total_count' => $totalCount,
            'success_count' => $successCount,
            'failed_count' => $failedCount,

            'started_at' => $startedAt,
            'completed_at' => $completedAt,
        ]);
    }

    public function parseAddressForManualChoice(Order $order)
    {
        return $this->parseRecipientAddress($order);
    }

    public function searchSettlementOptions($deliveryCity)
    {
        $search = $this->normalizeSettlementSearch(
            $deliveryCity
        );

        if ($search === '') {
            throw new RuntimeException(
                'Не вказано населений пункт одержувача.'
            );
        }

        $response = $this->client->call(
            'AddressGeneral',
            'searchSettlements',
            [
                'CityName' => $search,
                'Limit' => '100',
                'Page' => '1',
            ]
        );

        $options = [];

        foreach ($response as $group) {
            if (
                !is_array($group)
                || empty($group['Addresses'])
                || !is_array($group['Addresses'])
            ) {
                continue;
            }

            foreach ($group['Addresses'] as $address) {
                if (!is_array($address)) {
                    continue;
                }

                $settlementRef = trim(
                    (string) ($address['Ref'] ?? '')
                );

                $cityRef = trim(
                    (string) ($address['DeliveryCity'] ?? '')
                );

                if (
                    !$this->isUuid($settlementRef)
                    || !$this->isUuid($cityRef)
                    || $cityRef ===
                        '00000000-0000-0000-0000-000000000000'
                ) {
                    continue;
                }

                $options[$settlementRef] = [
                    'settlement_ref' => $settlementRef,
                    'city_ref' => $cityRef,

                    'name' => trim(
                        (string) (
                            $address['MainDescription'] ?? ''
                        )
                    ),

                    'area' => trim(
                        (string) ($address['Area'] ?? '')
                    ),

                    'region' => trim(
                        (string) ($address['Region'] ?? '')
                    ),

                    'type' => trim(
                        (string) (
                            $address['SettlementTypeCode'] ?? ''
                        )
                    ),
                ];
            }
        }

        $options = array_values($options);

        usort($options, function ($first, $second) {
            $firstLabel =
                ($first['area'] ?? '') . ' ' .
                ($first['region'] ?? '') . ' ' .
                ($first['name'] ?? '');

            $secondLabel =
                ($second['area'] ?? '') . ' ' .
                ($second['region'] ?? '') . ' ' .
                ($second['name'] ?? '');

            return strcmp($firstLabel, $secondLabel);
        });

        return $options;
    }

    public function selectSettlementOption(
        $deliveryCity,
        $selectedSettlementRef
    ) {
        $selectedSettlementRef = trim(
            (string) $selectedSettlementRef
        );

        foreach (
            $this->searchSettlementOptions($deliveryCity)
            as $option
        ) {
            if (
                strtolower($option['settlement_ref'])
                === strtolower($selectedSettlementRef)
            ) {
                return $option;
            }
        }

        throw new RuntimeException(
            'Вибраний населений пункт більше не знайдено ' .
            'у довіднику Нової Пошти.'
        );
    }

    public function searchStreetOptions(
        $cityRef,
        $settlementRef,
        $rawStreet
    ) {
        $cityRef = trim((string) $cityRef);
        $settlementRef = trim((string) $settlementRef);

        $search = $this->normalizeStreetSearch(
            $rawStreet
        );

        if ($search === '') {
            throw new RuntimeException(
                'Не вдалося визначити назву вулиці.'
            );
        }

        /*
         * Сначала ищем улицу непосредственно
         * в выбранном населённом пункте.
         */
        if ($this->isUuid($settlementRef)) {
            $response = $this->client->call(
                'AddressGeneral',
                'searchSettlementStreets',
                [
                    'SettlementRef' => $settlementRef,
                    'StreetName' => $search,
                    'Limit' => '100',
                ]
            );

            $options = [];

            foreach ($response as $group) {
                if (
                    !is_array($group)
                    || empty($group['Addresses'])
                    || !is_array($group['Addresses'])
                ) {
                    continue;
                }

                foreach ($group['Addresses'] as $street) {
                    if (!is_array($street)) {
                        continue;
                    }

                    $streetRef = trim(
                        (string) (
                            $street['SettlementStreetRef']
                            ?? ''
                        )
                    );

                    $description = trim(
                        (string) (
                            $street[
                                'SettlementStreetDescription'
                            ] ?? ''
                        )
                    );

                    if (
                        !$this->isUuid($streetRef)
                        || $description === ''
                    ) {
                        continue;
                    }

                    $options[$streetRef] = [
                        'street_ref' => $streetRef,

                        'name' => $description,

                        'name_ru' => trim(
                            (string) (
                                $street[
                                    'SettlementStreetDescriptionRu'
                                ] ?? ''
                            )
                        ),

                        'present' => trim(
                            (string) (
                                $street['Present'] ?? ''
                            )
                        ),

                        'type' => trim(
                            (string) (
                                $street[
                                    'StreetsTypeDescription'
                                ] ?? ''
                            )
                        ),

                        'source' => 'settlement',
                    ];
                }
            }

            if (!empty($options)) {
                return array_values($options);
            }
        }

        /*
         * Резервный старый справочник.
         * Он работает для крупных городов.
         */
        if (!$this->isUuid($cityRef)) {
            return [];
        }

        $streets = $this->client->call(
            'Address',
            'getStreet',
            [
                'CityRef' => $cityRef,
                'FindByString' => $search,
                'Page' => '1',
            ]
        );

        $options = [];

        foreach ($streets as $street) {
            if (!is_array($street)) {
                continue;
            }

            $streetRef = trim(
                (string) ($street['Ref'] ?? '')
            );

            $description = trim(
                (string) ($street['Description'] ?? '')
            );

            if (
                !$this->isUuid($streetRef)
                || $description === ''
            ) {
                continue;
            }

            $options[$streetRef] = [
                'street_ref' => $streetRef,

                'name' => $description,

                'name_ru' => trim(
                    (string) (
                        $street['DescriptionRu'] ?? ''
                    )
                ),

                'present' => $description,

                'type' => trim(
                    (string) (
                        $street[
                            'StreetsTypeDescription'
                        ] ?? ''
                    )
                ),

                'source' => 'city',
            ];
        }

        return array_values($options);
    }

    public function selectStreetOption(
        $cityRef,
        $settlementRef,
        $rawStreet,
        $selectedStreetRef
    ) {
        $selectedStreetRef = trim(
            (string) $selectedStreetRef
        );

        $options = $this->searchStreetOptions(
            $cityRef,
            $settlementRef,
            $rawStreet
        );

        foreach ($options as $option) {
            if (
                strtolower($option['street_ref'])
                === strtolower($selectedStreetRef)
            ) {
                return $option;
            }
        }

        throw new RuntimeException(
            'Вибрану вулицю більше не знайдено ' .
            'у довіднику Нової Пошти.'
        );
    }
}