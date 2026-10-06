<?php

namespace App\Services\Rozetka;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class RozetkaClient
{
    /**
     * @var string
     */
    private $apiUrl;

    /**
     * @var string
     */
    private $token;

    /**
     * @var int
     */
    private $timeout;

    public function __construct()
    {
        $this->apiUrl = rtrim(
            trim(
                (string) config(
                    'services.rozetka.api_url',
                    'https://api-cabinet-supplier.rozetka.com.ua'
                )
            ),
            '/'
        );

        $this->token = trim(
            (string) config(
                'services.rozetka.jwt_token',
                ''
            )
        );

        $this->timeout = (int) config(
            'services.rozetka.timeout',
            40
        );
    }

    /**
     * Получить список Dropshipping-заказов.
     *
     * Поддерживаемые параметры:
     * dateFrom
     * dateTo
     * status
     * page
     *
     * @param array $query
     * @return array
     */
    public function getOrders(array $query = [])
    {
        return $this->request(
            'GET',
            '/dropshipping/orders',
            $query
        );
    }

    /**
     * Получить один заказ.
     *
     * @param string $orderId
     * @return array
     */
    public function getOrder($orderId)
    {
        return $this->request(
            'GET',
            '/dropshipping/orders/' . urlencode($orderId)
        );
    }

    /**
     * Подтвердить позиции заказа.
     *
     * $products:
     *
     * [
     *     [
     *         'supplierCode' => '...',
     *         'confirmedQuantity' => 1,
     *         'serialNumbers' => [],
     *     ],
     * ]
     *
     * @param string $orderId
     * @param array $products
     * @return array
     */
    public function confirmOrder(
        $orderId,
        array $products
    ) {
        $this->ensureActionsEnabled();

        return $this->request(
            'POST',
            '/dropshipping/orders/confirm/' .
                urlencode($orderId),
            [
                'products' => $products,
            ]
        );
    }

    /**
     * Создать ТТН через Rozetka.
     *
     * Один элемент cargoSeats = одно место отправления.
     *
     * @param string $orderId
     * @param array $cargoSeats
     * @param bool $replaceExisting
     * @return array
     */
    public function createTtn(
    $orderId,
        array $cargoSeats,
        $replaceExisting = false
    ) {
        $this->ensureActionsEnabled();

        return $this->request(
            'POST',
            '/dropshipping/orders/ttn/' .
                urlencode($orderId),
            [
                'deliveryService' => 'nova_poshta',
                'cargoSeats' => $cargoSeats,
                'replaceExisting' => (bool) $replaceExisting,
            ]
        );
    }

    /**
     * Массовая печать маркировок.
     *
     * API возвращает PDF/HTML файл, а не JSON.
     *
     * @param array $payload
     * @return array
     */
    public function printMarkings(array $payload)
    {
        return $this->requestFile(
            'POST',
            '/dropshipping/orders/print-markings',
            $payload
        );
    }

    /**
     * Выполнить запрос к Supplier API Rozetka.
     *
     * @param string $method
     * @param string $path
     * @param array $data
     * @return array
     */
    private function request(
        $method,
        $path,
        array $data = []
    ) {
        $this->ensureConfigured();

        $url = $this->apiUrl . '/' . ltrim(
            $path,
            '/'
        );

        try {
            $request = Http::withToken($this->token)
                ->acceptJson()
                ->asJson()
                ->timeout($this->timeout);

            /*
             * GET-запросы безопасно повторять автоматически.
             */
            if (strtoupper($method) === 'GET') {
                $response = $request
                    ->retry(2, 500)
                    ->get(
                        $url,
                        $data
                    );
            } else {
                /*
                 * POST автоматически НЕ повторяем.
                 *
                 * Особенно важно для создания ТТН:
                 * внешний сервис мог выполнить действие,
                 * даже если мы не получили корректный ответ.
                 */
                $response = $request->send(
                    strtoupper($method),
                    $url,
                    [
                        'json' => $data,
                    ]
                );
            }
        } catch (Throwable $e) {
            throw new RozetkaApiException(
                'Не вдалося підключитися до API Rozetka: ' .
                $e->getMessage(),
                null,
                true,
                $e
            );
        }

        $result = $response->json();

        if (!$response->successful()) {
            throw new RozetkaApiException(
                $this->buildErrorMessage(
                    $response->status(),
                    $result,
                    $response->body()
                ),
                $response->status(),
                false
            );
        }

        if ($result === null) {
            return [];
        }

        if (!is_array($result)) {
            throw new RuntimeException(
                'API Rozetka повернув некоректну JSON-відповідь.'
            );
        }

        return $result;
    }

    /**
     * Выполнить запрос, который возвращает файл.
     *
     * Используется для print-markings.
     *
     * @param string $method
     * @param string $path
     * @param array $data
     * @return array
     */
    private function requestFile(
        $method,
        $path,
        array $data = []
    ) {
        $this->ensureConfigured();

        $url = $this->apiUrl . '/' . ltrim(
            $path,
            '/'
        );

        try {
            /*
             * POST print-markings также не повторяем
             * автоматически.
             */
            $response = Http::withToken($this->token)
                ->withHeaders([
                    'Accept' =>
                        'application/pdf, text/html',
                ])
                ->asJson()
                ->timeout($this->timeout)
                ->send(
                    strtoupper($method),
                    $url,
                    [
                        'json' => $data,
                    ]
                );
        } catch (Throwable $e) {
            throw new RozetkaApiException(
                'Не вдалося підключитися до API Rozetka: ' .
                $e->getMessage(),
                null,
                true,
                $e
            );
        }

        if (!$response->successful()) {
            throw new RozetkaApiException(
                $this->buildErrorMessage(
                    $response->status(),
                    $result,
                    $response->body()
                ),
                $response->status(),
                false
            );
        }

        $contentType = trim(
            (string) $response->header(
                'Content-Type'
            )
        );

        $normalizedContentType =
            strtolower($contentType);

        if (
            strpos(
                $normalizedContentType,
                'application/pdf'
            ) !== 0 &&
            strpos(
                $normalizedContentType,
                'text/html'
            ) !== 0
        ) {
            throw new RuntimeException(
                'API Rozetka повернув невідомий тип ' .
                'файлу маркування: ' .
                (
                    $contentType !== ''
                        ? $contentType
                        : 'не вказано'
                )
            );
        }

        return [
            /*
             * Бинарное содержимое PDF
             * либо HTML-строка.
             */
            'body' =>
                $response->body(),

            'content_type' =>
                $contentType,

            'content_disposition' =>
                $response->header(
                    'Content-Disposition'
                ),

            'printed' =>
                (int) $response->header(
                    'X-Markings-Printed'
                ),

            'skipped' =>
                (int) $response->header(
                    'X-Markings-Skipped'
                ),
        ];
    }

    /**
     * Запретить изменяющие действия через новый API,
     * пока они явно не включены в .env.
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

    /**
     * Проверить конфигурацию.
     *
     * @return void
     */
    private function ensureConfigured()
    {
        if ($this->apiUrl === '') {
            throw new RuntimeException(
                'Не задано ROZETKA_API_URL у файлі .env.'
            );
        }

        if ($this->token === '') {
            throw new RuntimeException(
                'Не задано ROZETKA_API_TOKEN у файлі .env.'
            );
        }
    }

    /**
     * Собрать читаемое сообщение ошибки.
     *
     * @param int $status
     * @param mixed $result
     * @param string $body
     * @return string
     */
    private function buildErrorMessage(
        $status,
        $result,
        $body
    ) {
        $messages = [];

        if (is_array($result)) {
            foreach ([
                'message',
                'error',
                'errors',
                'detail',
                'warnings',
            ] as $key) {
                if (!array_key_exists($key, $result)) {
                    continue;
                }

                $this->collectMessages(
                    $result[$key],
                    $messages
                );
            }
        }

        $messages = array_values(
            array_unique(
                array_filter($messages)
            )
        );

        if (!empty($messages)) {
            return sprintf(
                'API Rozetka повернув HTTP %d: %s',
                $status,
                implode('; ', $messages)
            );
        }

        return sprintf(
            'API Rozetka повернув HTTP %d. Відповідь: %s',
            $status,
            mb_substr(
                (string) $body,
                0,
                2000
            )
        );
    }

    /**
     * @param mixed $value
     * @param array $messages
     * @return void
     */
    private function collectMessages(
        $value,
        array &$messages
    ) {
        if (is_scalar($value)) {
            $messages[] = (string) $value;
            return;
        }

        if (!is_array($value)) {
            return;
        }

        foreach ($value as $item) {
            $this->collectMessages(
                $item,
                $messages
            );
        }
    }
}