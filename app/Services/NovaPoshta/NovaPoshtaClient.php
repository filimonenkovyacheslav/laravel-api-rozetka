<?php

namespace App\Services\NovaPoshta;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class NovaPoshtaClient
{
    /**
     * @var string
     */
    private $apiKey;

    /**
     * @var string
     */
    private $apiUrl;

    public function __construct()
    {
        $this->apiKey = trim(
            (string) config('services.nova_poshta.api_key', '')
        );

        $this->apiUrl = trim(
            (string) config(
                'services.nova_poshta.api_url',
                'https://api.novaposhta.ua/v2.0/json/'
            )
        );
    }

    /**
     * Выполнить запрос к API Новой Почты.
     *
     * @param string $modelName
     * @param string $calledMethod
     * @param array  $methodProperties
     *
     * @return array
     */
    public function call(
        $modelName,
        $calledMethod,
        array $methodProperties = []
    ) {
        $this->ensureConfigured();

        try {
            $response = Http::asJson()
                ->acceptJson()
                ->timeout(40)
                ->retry(2, 500)
                ->post($this->apiUrl, [
                    'apiKey' => $this->apiKey,
                    'modelName' => $modelName,
                    'calledMethod' => $calledMethod,
                    'methodProperties' => $methodProperties,
                ]);
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Не вдалося підключитися до API Нової Пошти: ' .
                $e->getMessage(),
                0,
                $e
            );
        }

        if (!$response->successful()) {
            throw new RuntimeException(
                'API Нової Пошти повернув HTTP ' .
                $response->status() .
                '. Відповідь: ' .
                mb_substr($response->body(), 0, 2000)
            );
        }

        $result = $response->json();

        if (!is_array($result)) {
            throw new RuntimeException(
                'API Нової Пошти повернув некоректну JSON-відповідь.'
            );
        }

        if (empty($result['success'])) {
            throw new RuntimeException(
                $this->buildErrorMessage($result)
            );
        }

        return isset($result['data']) &&
            is_array($result['data'])
                ? $result['data']
                : [];
    }

    private function ensureConfigured()
    {
        if ($this->apiKey === '') {
            throw new RuntimeException(
                'Не задано NOVA_POSHTA_API_KEY у файлі .env.'
            );
        }

        if ($this->apiUrl === '') {
            throw new RuntimeException(
                'Не задано NOVA_POSHTA_API_URL у файлі .env.'
            );
        }
    }

    private function buildErrorMessage(array $result)
    {
        $messages = [];

        foreach ([
            'errors',
            'warnings',
            'info',
            'messageCodes',
            'errorCodes',
        ] as $key) {
            if (empty($result[$key])) {
                continue;
            }

            $values = is_array($result[$key])
                ? $result[$key]
                : [$result[$key]];

            foreach ($values as $value) {
                if (is_scalar($value)) {
                    $messages[] = (string) $value;
                }
            }
        }

        $messages = array_values(array_unique(
            array_filter($messages)
        ));

        if (empty($messages)) {
            return 'Нова Пошта відхилила API-запит без опису помилки.';
        }

        return 'Помилка API Нової Пошти: ' .
            implode('; ', $messages);
    }
}