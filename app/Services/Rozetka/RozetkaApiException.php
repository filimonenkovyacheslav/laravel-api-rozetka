<?php

namespace App\Services\Rozetka;

use RuntimeException;
use Throwable;

class RozetkaApiException extends RuntimeException
{
    /**
     * @var int|null
     */
    private $httpStatus;

    /**
     * Ошибка соединения/timeout:
     * неизвестно, успел ли сервер выполнить POST.
     *
     * @var bool
     */
    private $transportError;

    public function __construct(
        $message,
        $httpStatus = null,
        $transportError = false,
        Throwable $previous = null
    ) {
        parent::__construct(
            $message,
            0,
            $previous
        );

        $this->httpStatus =
            $httpStatus !== null
                ? (int) $httpStatus
                : null;

        $this->transportError =
            (bool) $transportError;
    }

    /**
     * @return int|null
     */
    public function getHttpStatus()
    {
        return $this->httpStatus;
    }

    /**
     * @return bool
     */
    public function isTransportError()
    {
        return $this->transportError;
    }
}