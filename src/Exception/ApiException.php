<?php

declare(strict_types=1);

namespace MaxMessenger\Sender\Exception;

/**
 * Ошибка, которую вернул API Max.
 *
 * Возникает, если сервер ответил кодом HTTP вне диапазона `2xx`.
 */
final class ApiException extends SenderException
{
    private ?string $errorCode;
    private int $httpCode;

    /**
     * @param int $httpCode Код ответа HTTP.
     * @param string|null $errorCode Код ошибки API, например `attachment.not.ready`.
     * @param string $message Описание ошибки.
     */
    public function __construct(int $httpCode, ?string $errorCode, string $message)
    {
        parent::__construct($message);

        $this->httpCode = $httpCode;
        $this->errorCode = $errorCode;
    }

    /**
     * @return string|null Код ошибки API или `null`, если сервер его не прислал.
     */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * @return int Код ответа HTTP.
     */
    public function getHttpCode(): int
    {
        return $this->httpCode;
    }
}
