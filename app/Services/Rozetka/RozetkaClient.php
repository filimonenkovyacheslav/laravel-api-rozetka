<?php

namespace App\Services\Rozetka;

use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
     * expand
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
        $this->ensureActionsEnabled();

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

        $isTtnRequest =
            strpos(
                $path,
                '/dropshipping/orders/ttn/'
            ) === 0;

        if ($isTtnRequest) {
            Log::info('ROZETKA TTN REQUEST', [
                'method' => strtoupper($method),
                'url' => $url,
                'payload' => $data,
            ]);
        }

        try {
            $this->waitForRateLimit();
            
            $request = Http::withToken($this->token)
                ->acceptJson()
                ->asJson()
                ->timeout($this->timeout);

            /*
             * GET-запросы безопасно повторять автоматически.
             *
             * POST автоматически не повторяем:
             * внешний сервис мог выполнить действие,
             * даже если корректный ответ не был получен.
             */
            if (strtoupper($method) === 'GET') {
                $response = $request
                ->retry(
                    2,
                    1100,
                    function ($exception) {
                        /*
                         * Повторяем только безопасные GET,
                         * если проблема действительно временная:
                         *
                         * - transport / connection error;
                         * - HTTP 5xx.
                         *
                         * HTTP 4xx, включая 429, автоматически
                         * не повторяем.
                         */
                        if (
                            $exception instanceof
                                ConnectionException
                        ) {
                            return true;
                        }

                        if (
                            $exception instanceof
                                RequestException &&
                            $exception->response
                        ) {
                            return
                                $exception->response
                                    ->status() >= 500;
                        }

                        return false;
                    }
                )
                ->get(
                    $url,
                    $data
                );
            } else {
                $response = $request->send(
                    strtoupper($method),
                    $url,
                    [
                        'json' => $data,
                    ]
                );
            }
        } catch (RequestException $e) {
            /*
             * retry() может выбросить RequestException
             * после последней HTTP-ошибки.
             *
             * Это не transport error: соединение с API
             * состоялось и Rozetka вернула HTTP-ответ.
             *
             * Особенно важно корректно различать 429,
             * 4xx и 5xx от ошибок сети.
             */
            $response = $e->response;

            if ($response) {
                $result = $response->json();

                if ($isTtnRequest) {
                    Log::info('ROZETKA TTN RESPONSE', [
                        'status' => $response->status(),

                        'content_type' =>
                            $response->header('Content-Type'),

                        'rate_limit_limit' =>
                            $response->header('X-RateLimit-Limit'),

                        'rate_limit_remaining' =>
                            $response->header('X-RateLimit-Remaining'),

                        'retry_after' =>
                            $response->header('Retry-After'),

                        'json' => $result,

                        'body' => is_array($result)
                            ? null
                            : $response->body(),
                    ]);
                }

                if ($response->status() === 429) {
                    Log::warning('ROZETKA RATE LIMIT', [
                        'method' => strtoupper($method),
                        'path' => $path,
                        'status' => $response->status(),

                        'retry_after' =>
                            $response->header('Retry-After'),

                        'rate_limit_limit' =>
                            $response->header('X-RateLimit-Limit'),

                        'rate_limit_remaining' =>
                            $response->header('X-RateLimit-Remaining'),

                        'rate_limit_reset' =>
                            $response->header('X-RateLimit-Reset'),

                        'body' => $response->body(),
                    ]);
                }

                if ($isTtnRequest) {
                    Log::info('ROZETKA TTN RESPONSE', [
                        'status' => $response->status(),

                        'content_type' =>
                            $response->header('Content-Type'),

                        'rate_limit_limit' =>
                            $response->header('X-RateLimit-Limit'),

                        'rate_limit_remaining' =>
                            $response->header('X-RateLimit-Remaining'),

                        'retry_after' =>
                            $response->header('Retry-After'),

                        'json' => $result,

                        'body' => is_array($result)
                            ? null
                            : $response->body(),
                    ]);
                }

                if (!is_array($result)) {
                    $result = null;
                }

                throw new RozetkaApiException(
                    $this->buildErrorMessage(
                        $response->status(),
                        $result,
                        $response->body()
                    ),
                    $response->status(),
                    false,
                    $e
                );
            }

            throw new RozetkaApiException(
                'Не вдалося підключитися до API Rozetka: ' .
                $e->getMessage(),
                null,
                true,
                $e
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
            $result = $response->json();

            if (!is_array($result)) {
                $result = null;
            }

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

        $result = $response->json();

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
            $this->waitForRateLimit();
            
            /*
             * POST print-markings также не повторяем
             * автоматически.
             */
            $response = Http::withToken($this->token)
                ->withHeaders([
                    /*
                     * Rozetka Supplier API uses Yii content negotiation.
                     * It can reject PDF/HTML-only Accept with HTTP 406
                     * before the marking endpoint generates the PDF.
                     */
                    'Accept' =>
                        '*/*',
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
            $result = $response->json();

            if (!is_array($result)) {
                $result = null;
            }

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

    private function waitForRateLimit()
    {
        $lockPath = storage_path(
            'framework/rozetka-api-rate-limit.lock'
        );

        $handle = fopen($lockPath, 'c+');

        if ($handle === false) {
            throw new RuntimeException(
                'Не вдалося відкрити файл блокування Rozetka API.'
            );
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException(
                    'Не вдалося заблокувати Rozetka API.'
                );
            }

            rewind($handle);

            $contents = trim(
                (string) stream_get_contents($handle)
            );

            $lastRequestAt = $contents !== ''
                ? (float) $contents
                : 0.0;

            $now = microtime(true);

            /*
             * Минимум 1.1 секунды между началом
             * любых двух запросов к Rozetka API
             * на этом сервере.
             */
            $minimumInterval = 2.0;

            if ($lastRequestAt > 0) {
                $elapsed = $now - $lastRequestAt;

                if ($elapsed < $minimumInterval) {
                    usleep(
                        (int) ceil(
                            ($minimumInterval - $elapsed)
                            * 1000000
                        )
                    );
                }
            }

            $requestStartedAt = microtime(true);

            rewind($handle);
            ftruncate($handle, 0);

            fwrite(
                $handle,
                sprintf('%.6F', $requestStartedAt)
            );

            fflush($handle);

            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }
}