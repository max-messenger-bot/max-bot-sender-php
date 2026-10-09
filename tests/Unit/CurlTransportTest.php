<?php

declare(strict_types=1);

namespace MaxMessenger\Sender\Tests\Unit;

use MaxMessenger\Sender\Exception\TransportException;
use MaxMessenger\Sender\Transport\CurlTransport;
use PHPUnit\Framework\TestCase;

use function is_file;

use const CURLOPT_CAINFO;
use const CURLOPT_CAPATH;
use const CURLOPT_CONNECTTIMEOUT_MS;
use const CURLOPT_HTTPPROXYTUNNEL;
use const CURLOPT_PROXY;
use const CURLOPT_PROXYTYPE;
use const CURLOPT_TIMEOUT_MS;
use const CURLPROXY_HTTP;
use const CURLPROXY_SOCKS5;

final class CurlTransportTest extends TestCase
{
    public function testConstructorOptions(): void
    {
        $options = (new CurlTransport(3000, 1000, false))->getOptions();

        $this->assertSame(3000, $options[CURLOPT_TIMEOUT_MS]);
        $this->assertSame(1000, $options[CURLOPT_CONNECTTIMEOUT_MS]);
        $this->assertArrayNotHasKey(CURLOPT_CAINFO, $options);
    }

    public function testNetworkError(): void
    {
        $this->expectException(TransportException::class);

        $response = (new CurlTransport(1000, 1000, false))->post('http://127.0.0.1:1/messages', [], '{}');

        $this->fail("Unexpected HTTP response $response[0].");
    }

    public function testProxy(): void
    {
        $transport = (new CurlTransport())->setProxy('socks5.local:1080', true);

        $options = $transport->getOptions();
        $this->assertSame('socks5.local:1080', $options[CURLOPT_PROXY]);
        $this->assertTrue($options[CURLOPT_HTTPPROXYTUNNEL]);
        $this->assertSame(CURLPROXY_SOCKS5, $options[CURLOPT_PROXYTYPE]);

        $transport->setProxy('http://proxy.local:3128');
        $this->assertSame(CURLPROXY_HTTP, $transport->getOptions()[CURLOPT_PROXYTYPE]);

        $options = $transport->setProxy()->getOptions();
        $this->assertArrayNotHasKey(CURLOPT_PROXY, $options);
        $this->assertArrayNotHasKey(CURLOPT_HTTPPROXYTUNNEL, $options);
        $this->assertArrayNotHasKey(CURLOPT_PROXYTYPE, $options);
    }

    public function testSetCaCertificates(): void
    {
        $transport = (new CurlTransport())
            ->setCaCertificatePath('/etc/ssl/custom/ca.pem')
            ->setCaCertificateDir('/etc/ssl/custom/certs');

        $options = $transport->getOptions();
        $this->assertSame('/etc/ssl/custom/ca.pem', $options[CURLOPT_CAINFO]);
        $this->assertSame('/etc/ssl/custom/certs', $options[CURLOPT_CAPATH]);

        $options = $transport->setCaCertificatePath(null)->setCaCertificateDir(null)->getOptions();
        $this->assertArrayNotHasKey(CURLOPT_CAINFO, $options);
        $this->assertArrayNotHasKey(CURLOPT_CAPATH, $options);
    }

    public function testSetOption(): void
    {
        $transport = (new CurlTransport())->setOption(CURLOPT_TIMEOUT_MS, 500);
        $this->assertSame(500, $transport->getOptions()[CURLOPT_TIMEOUT_MS]);

        $transport->setOption(CURLOPT_TIMEOUT_MS, null);
        $this->assertArrayNotHasKey(CURLOPT_TIMEOUT_MS, $transport->getOptions());
    }

    public function testUseRussianTrustedCaCertificates(): void
    {
        $default = (new CurlTransport())->getOptions();
        $explicit = (new CurlTransport(10000, 5000, false))->useRussianTrustedCaCertificates()->getOptions();

        $this->assertIsString($default[CURLOPT_CAINFO]);
        $this->assertTrue(is_file($default[CURLOPT_CAINFO]));
        $this->assertSame($default[CURLOPT_CAINFO], $explicit[CURLOPT_CAINFO]);
    }
}
