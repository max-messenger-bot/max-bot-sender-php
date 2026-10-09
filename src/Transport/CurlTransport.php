<?php

declare(strict_types=1);

namespace MaxMessenger\Sender\Transport;

use LogicException;
use MaxMessenger\Sender\Exception\TransportException;

use function curl_errno;
use function curl_error;
use function curl_exec;
use function curl_getinfo;
use function curl_init;
use function curl_setopt_array;
use function dirname;
use function is_string;

use const CURLINFO_HTTP_CODE;
use const CURLOPT_CAINFO;
use const CURLOPT_CAPATH;
use const CURLOPT_CONNECTTIMEOUT_MS;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_HTTPPROXYTUNNEL;
use const CURLOPT_POST;
use const CURLOPT_POSTFIELDS;
use const CURLOPT_PROXY;
use const CURLOPT_PROXYTYPE;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_TIMEOUT_MS;
use const CURLOPT_URL;
use const CURLOPT_USERAGENT;
use const CURLPROXY_HTTP;
use const CURLPROXY_SOCKS5;

/**
 * Выполнение запросов через curl.
 *
 * Сертификат API Max выпущен удостоверяющим центром Минцифры, которого нет в большинстве системных хранилищ
 * доверия, поэтому по умолчанию используются корневые сертификаты Минцифры, поставляемые с пакетом
 * (см. {@see useRussianTrustedCaCertificates()}).
 * Собственные сертификаты задаются методами {@see setCaCertificatePath()} и {@see setCaCertificateDir()}.
 */
final class CurlTransport implements TransportInterface
{
    /**
     * @var non-empty-string Значение заголовка `User-Agent`.
     */
    public const USER_AGENT = 'mj4444-MaxMessenger-Sender';

    /**
     * @var array<int, mixed> Параметры curl.
     */
    private array $options;

    /**
     * @param int $timeout Сколько **миллисекунд** может выполняться запрос. `0` — без ограничения.
     * @param int $connectTimeout Сколько **миллисекунд** ждать подключения. `0` — ждать сколько угодно.
     * @param bool $useRussianTrustedCaCertificates Доверять корневым сертификатам Минцифры, поставляемым с пакетом
     *     (см. {@see useRussianTrustedCaCertificates()}).
     */
    public function __construct(
        int $timeout = 10000,
        int $connectTimeout = 5000,
        bool $useRussianTrustedCaCertificates = true
    ) {
        $this->options = [
            CURLOPT_CONNECTTIMEOUT_MS => $connectTimeout,
            CURLOPT_TIMEOUT_MS => $timeout,
            CURLOPT_USERAGENT => self::USER_AGENT,
        ];

        if ($useRussianTrustedCaCertificates) {
            $this->useRussianTrustedCaCertificates();
        }
    }

    /**
     * @return array<int, mixed> Параметры curl.
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    public function post(string $url, array $headers, string $body): array
    {
        $curlHeaders = [];
        foreach ($headers as $name => $value) {
            $curlHeaders[] = $name . ': ' . $value;
        }

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_RETURNTRANSFER => true,
        ] + $this->options;

        $curlHandle = curl_init();
        if ($curlHandle === false) {
            throw new LogicException('Failed to initialize curl.');
        }

        curl_setopt_array($curlHandle, $options);

        $response = curl_exec($curlHandle);

        if (!is_string($response)) {
            throw new TransportException(curl_error($curlHandle), curl_errno($curlHandle));
        }

        return [(int) curl_getinfo($curlHandle, CURLINFO_HTTP_CODE), $response];
    }

    /**
     * Задаёт каталог с корневыми сертификатами для проверки сервера.
     *
     * Сертификаты в каталоге должны быть подготовлены утилитой `openssl rehash` (или `c_rehash`).
     *
     * @param non-empty-string|null $caCertificateDir Путь к каталогу с сертификатами удостоверяющих центров
     *     (`CURLOPT_CAPATH`). `null` — использовать системный каталог.
     * @return $this
     */
    public function setCaCertificateDir(?string $caCertificateDir): self
    {
        return $this->setOption(CURLOPT_CAPATH, $caCertificateDir);
    }

    /**
     * Задаёт файл с корневыми сертификатами для проверки сервера.
     *
     * Заменяет набор сертификатов Минцифры, если он был включён ({@see useRussianTrustedCaCertificates()}).
     *
     * @param non-empty-string|null $caCertificatePath Путь к файлу с сертификатами удостоверяющих центров
     *     в формате PEM (`CURLOPT_CAINFO`). `null` — использовать системные сертификаты.
     * @return $this
     */
    public function setCaCertificatePath(?string $caCertificatePath): self
    {
        return $this->setOption(CURLOPT_CAINFO, $caCertificatePath);
    }

    /**
     * Задаёт произвольный параметр curl.
     *
     * @param int $option Константа `CURLOPT_*`.
     * @param mixed $value Значение параметра. `null` удаляет параметр.
     * @return $this
     */
    public function setOption(int $option, $value): self
    {
        if ($value === null) {
            unset($this->options[$option]);
        } else {
            $this->options[$option] = $value;
        }

        return $this;
    }

    /**
     * Задаёт прокси-сервер.
     *
     * @param string $proxy Адрес прокси-сервера, например `http://user:password@proxy.local:3128`.
     *     Пустая строка отключает прокси.
     * @param bool $socks5 Если `true`, используется SOCKS5 вместо HTTP.
     * @return $this
     */
    public function setProxy(string $proxy = '', bool $socks5 = false): self
    {
        if ($proxy === '') {
            unset(
                $this->options[CURLOPT_PROXY],
                $this->options[CURLOPT_HTTPPROXYTUNNEL],
                $this->options[CURLOPT_PROXYTYPE],
            );

            return $this;
        }

        $this->options[CURLOPT_PROXY] = $proxy;
        $this->options[CURLOPT_HTTPPROXYTUNNEL] = true;
        $this->options[CURLOPT_PROXYTYPE] = $socks5 ? CURLPROXY_SOCKS5 : CURLPROXY_HTTP;

        return $this;
    }

    /**
     * Доверяет корневым сертификатам Минцифры, поставляемым с пакетом.
     *
     * Указывает curl (`CURLOPT_CAINFO`) на набор сертификатов из `resources/certs`.
     * Нужен, когда API Max отдаёт сертификат, выданный центром Минцифры, которого нет в системном хранилище доверия.
     *
     * @return $this
     */
    public function useRussianTrustedCaCertificates(): self
    {
        return $this->setCaCertificatePath(dirname(__DIR__, 2) . '/resources/certs/russian_trusted_ca_bundle.pem');
    }
}
