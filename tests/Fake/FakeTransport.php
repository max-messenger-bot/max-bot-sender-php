<?php

declare(strict_types=1);

namespace MaxMessenger\Sender\Tests\Fake;

use MaxMessenger\Sender\Exception\TransportException;
use MaxMessenger\Sender\Transport\TransportInterface;

use function array_shift;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Транспорт, который запоминает запрос и возвращает заранее заданные ответы.
 *
 * Сначала по очереди отдаются ответы из $queue, затем — ответ по умолчанию.
 */
final class FakeTransport implements TransportInterface
{
    /**
     * Сообщение, которое возвращается в ответе по умолчанию.
     */
    public const MESSAGE = [
        'body' => ['mid' => 'mid.1', 'seq' => 1, 'text' => 'Text'],
        'recipient' => ['chat_id' => 1, 'chat_type' => 'chat'],
        'sender' => ['user_id' => 2, 'first_name' => 'Bot', 'is_bot' => true, 'username' => 'test_bot'],
        'timestamp' => 1760000000000,
    ];

    public string $body = '';
    /**
     * @var array<non-empty-string, string>
     */
    public array $headers = [];
    /**
     * @var list<array{0: int, 1: string}|TransportException>
     */
    public array $queue = [];
    public int $requestCount = 0;
    public string $responseBody;
    public int $responseCode;
    public string $url = '';

    public function __construct(int $responseCode = 200, ?string $responseBody = null)
    {
        $this->responseCode = $responseCode;
        $this->responseBody = $responseBody ?? json_encode(['message' => self::MESSAGE], JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    public function getJsonBody(): array
    {
        /** @var array<string, mixed> */
        return json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
    }

    public function post(string $url, array $headers, string $body): array
    {
        $this->url = $url;
        $this->headers = $headers;
        $this->body = $body;
        $this->requestCount++;

        $response = array_shift($this->queue);
        if ($response instanceof TransportException) {
            throw $response;
        }

        return $response ?? [$this->responseCode, $this->responseBody];
    }
}
