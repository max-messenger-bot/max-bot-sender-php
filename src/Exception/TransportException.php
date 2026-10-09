<?php

declare(strict_types=1);

namespace MaxMessenger\Sender\Exception;

/**
 * Ошибка передачи запроса: ответ сервера не получен.
 *
 * Возникает при сетевых сбоях, истечении времени ожидания и ошибках TLS.
 */
final class TransportException extends SenderException {}
