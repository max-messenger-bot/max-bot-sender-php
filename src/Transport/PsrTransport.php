<?php

declare(strict_types=1);

namespace MaxMessenger\Sender\Transport;

use LogicException;
use MaxMessenger\Sender\Exception\TransportException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Выполнение запросов через любой клиент PSR-18.
 *
 * Подходит, когда в проекте уже есть настроенный клиент: Guzzle, Symfony HttpClient и подобные.
 * Таймауты, прокси и сертификаты задаются при настройке самого клиента.
 *
 * Сетевые ошибки клиента ({@see NetworkExceptionInterface}) превращаются в {@see TransportException},
 * прочие ошибки клиента ({@see ClientExceptionInterface}) пробрасываются как есть.
 */
final class PsrTransport implements TransportInterface
{
    private ClientInterface $client;
    private RequestFactoryInterface $requestFactory;
    private StreamFactoryInterface $streamFactory;

    /**
     * @param ClientInterface $client Клиент PSR-18, выполняющий запросы.
     * @param RequestFactoryInterface|null $requestFactory Фабрика запросов PSR-17.
     *     Если не задана, используется сам клиент, когда он реализует этот интерфейс.
     * @param StreamFactoryInterface|null $streamFactory Фабрика потоков PSR-17.
     *     Если не задана, используется сам клиент, когда он реализует этот интерфейс.
     */
    public function __construct(
        ClientInterface $client,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null
    ) {
        if ($requestFactory === null) {
            if (!$client instanceof RequestFactoryInterface) {
                throw new LogicException('PSR-17 request factory is required.');
            }
            $requestFactory = $client;
        }

        if ($streamFactory === null) {
            if (!$client instanceof StreamFactoryInterface) {
                throw new LogicException('PSR-17 stream factory is required.');
            }
            $streamFactory = $client;
        }

        $this->client = $client;
        $this->requestFactory = $requestFactory;
        $this->streamFactory = $streamFactory;
    }

    public function post(string $url, array $headers, string $body): array
    {
        $request = $this->requestFactory
            ->createRequest('POST', $url)
            ->withBody($this->streamFactory->createStream($body));

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        try {
            $response = $this->client->sendRequest($request);
        } catch (NetworkExceptionInterface $e) {
            throw new TransportException($e->getMessage(), $e->getCode(), $e);
        }

        return [$response->getStatusCode(), (string) $response->getBody()];
    }
}
