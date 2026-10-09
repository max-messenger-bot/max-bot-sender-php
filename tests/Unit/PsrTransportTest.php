<?php

declare(strict_types=1);

namespace MaxMessenger\Sender\Tests\Unit;

use LogicException;
use MaxMessenger\Sender\Exception\TransportException;
use MaxMessenger\Sender\Transport\PsrTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class PsrTransportTest extends TestCase
{
    public function testClientExceptionPassesThrough(): void
    {
        $client = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new class ('Invalid request') extends RuntimeException implements ClientExceptionInterface {};
            }
        };
        $factory = new Psr17Factory();

        $this->expectException(ClientExceptionInterface::class);
        $this->expectExceptionMessage('Invalid request');

        (new PsrTransport($client, $factory, $factory))->post('https://example.com', [], '{}');
    }

    public function testFactoriesAreRequired(): void
    {
        $client = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new Response();
            }
        };

        $this->expectException(LogicException::class);

        new PsrTransport($client);
    }

    public function testNetworkException(): void
    {
        $client = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                throw new class ($request) extends RuntimeException implements NetworkExceptionInterface {
                    private RequestInterface $request;

                    public function __construct(RequestInterface $request)
                    {
                        parent::__construct('Connection refused');

                        $this->request = $request;
                    }

                    public function getRequest(): RequestInterface
                    {
                        return $this->request;
                    }
                };
            }
        };
        $factory = new Psr17Factory();

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Connection refused');

        (new PsrTransport($client, $factory, $factory))->post('https://example.com', [], '{}');
    }

    public function testPost(): void
    {
        $client = new class implements ClientInterface {
            public ?RequestInterface $request = null;

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->request = $request;

                return new Response(400, [], '{"code":"bad"}');
            }
        };
        $factory = new Psr17Factory();

        $response = (new PsrTransport($client, $factory, $factory))->post(
            'https://example.com/messages?chat_id=1',
            ['Authorization' => 'token', 'Content-Type' => 'application/json'],
            '{"text":"Hi"}',
        );

        $this->assertSame([400, '{"code":"bad"}'], $response);
        $this->assertNotNull($client->request);
        $this->assertSame('POST', $client->request->getMethod());
        $this->assertSame('https://example.com/messages?chat_id=1', (string) $client->request->getUri());
        $this->assertSame('token', $client->request->getHeaderLine('Authorization'));
        $this->assertSame('application/json', $client->request->getHeaderLine('Content-Type'));
        $this->assertSame('{"text":"Hi"}', (string) $client->request->getBody());
    }
}
