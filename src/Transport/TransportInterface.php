<?php

declare(strict_types=1);

namespace MaxMessenger\Sender\Transport;

use MaxMessenger\Sender\Exception\TransportException;
use MaxMessenger\Sender\MaxSender;

/**
 * Способ выполнения HTTP-запросов к API Max.
 */
interface TransportInterface
{
    /**
     * Выполняет POST-запрос.
     *
     * @param non-empty-string $url Полный адрес запроса.
     * @param array<non-empty-string, string> $headers Заголовки запроса.
     * @param string $body Тело запроса.
     * @return array{0: int, 1: string} Код ответа HTTP и тело ответа.
     * @throws TransportException Если ответ сервера не получен из-за сетевого сбоя или истечения времени ожидания.
     *     Такая ошибка считается временной: {@see MaxSender} повторяет запрос.
     */
    public function post(string $url, array $headers, string $body): array;
}
