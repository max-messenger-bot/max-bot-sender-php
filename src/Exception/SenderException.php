<?php

declare(strict_types=1);

namespace MaxMessenger\Sender\Exception;

use RuntimeException;

/**
 * Базовая ошибка отправки сообщения.
 *
 * Возникает и сама по себе, если сервер вернул успешный ответ в неожиданном формате.
 */
class SenderException extends RuntimeException {}
