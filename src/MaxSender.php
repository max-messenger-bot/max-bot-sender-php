<?php

declare(strict_types=1);

namespace MaxMessenger\Sender;

use InvalidArgumentException;
use JsonException;
use LogicException;
use MaxMessenger\Sender\Exception\ApiException;
use MaxMessenger\Sender\Exception\SenderException;
use MaxMessenger\Sender\Exception\TransportException;
use MaxMessenger\Sender\Transport\CurlTransport;
use MaxMessenger\Sender\Transport\TransportInterface;
use SensitiveParameter;

use function array_shift;
use function count;
use function http_build_query;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function rtrim;
use function usleep;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_UNICODE;

/**
 * Отправка сообщений в мессенджер МАКС.
 *
 * Объект хранит токен бота, способ выполнения запросов и собираемое сообщение: текст, формат, вложения
 * и настройки отправки. Сообщение собирается цепочкой методов `set*` и `add*`, а отправляется методами
 * {@see sendToChat()} и {@see sendToUser()}. После отправки сообщение не очищается: его можно отправить
 * ещё раз другому получателю или очистить методом {@see reset()}.
 *
 * При временной ошибке — сетевом сбое, истечении времени ожидания, ответе HTTP 429, 500, 502, 503 или 504 —
 * отправка повторяется с задержками из {@see setRetryAttempts()}. Если вложение ещё не обработано сервером
 * (`attachment.not.ready`), отправка повторяется с задержками из {@see setAttachmentRetryAttempts()}.
 *
 * @link https://dev.max.ru/docs-api/methods/POST/messages
 */
final class MaxSender
{
    /**
     * @var non-empty-string Базовый адрес API Max.
     */
    public const BASE_URL = 'https://platform-api2.max.ru';
    /**
     * @var non-empty-string Разметка текста в формате HTML.
     */
    public const FORMAT_HTML = 'html';
    /**
     * @var non-empty-string Разметка текста в формате Markdown.
     */
    public const FORMAT_MARKDOWN = 'markdown';
    /**
     * @var positive-int Максимальное число кнопок в ряду клавиатуры.
     */
    public const MAX_BUTTONS_IN_ROW = 7;
    /**
     * @var positive-int Максимальное число рядов клавиатуры.
     */
    public const MAX_KEYBOARD_ROWS = 30;
    /**
     * @var list<int> Коды ответа HTTP, при которых отправка повторяется.
     */
    private const RETRY_HTTP_CODES = [429, 500, 502, 503, 504];

    /**
     * @var list<positive-int>|null Задержки перед повтором отправки при `attachment.not.ready`
     *     в миллисекундах. `null` — использовать $retryAttempts.
     */
    private ?array $attachmentRetryAttempts = null;
    /**
     * @var list<array<string, mixed>> Вложения сообщения.
     */
    private array $attachments = [];
    private string $baseUrl;
    private bool $disableLinkPreview = false;
    /**
     * @var self::FORMAT_*|null Разметка текста сообщения.
     */
    private ?string $format = null;
    /**
     * @var list<non-empty-list<array<string, mixed>>> Ряды кнопок встроенной клавиатуры.
     */
    private array $keyboard = [];
    private bool $keyboardNewRow = false;
    private bool $notify = true;
    /**
     * @var list<positive-int> Задержки перед повтором отправки при временной ошибке в миллисекундах.
     */
    private array $retryAttempts = [1000, 2000, 4000, 8000, 15000];
    private string $text = '';
    private string $token;
    private TransportInterface $transport;

    /**
     * @param string $token Токен доступа бота.
     * @param TransportInterface|null $transport Способ выполнения запросов.
     *     Если не задан, используется {@see CurlTransport} с настройками по умолчанию.
     * @param non-empty-string $baseUrl Базовый адрес API Max.
     */
    public function __construct(
        #[SensitiveParameter]
        string $token,
        ?TransportInterface $transport = null,
        string $baseUrl = self::BASE_URL
    ) {
        if ($token === '') {
            throw new InvalidArgumentException('Access token must not be empty.');
        }

        $this->token = $token;
        $this->transport = $transport ?? new CurlTransport();
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * Прикрепляет аудио к сообщению.
     *
     * Должно быть единственным вложением в сообщении.
     *
     * @param non-empty-string $token Токен загруженного аудио.
     * @return $this
     */
    public function addAudio(string $token): self
    {
        $this->attachments[] = ['type' => 'audio', 'payload' => ['token' => $token]];

        return $this;
    }

    /**
     * Добавляет в клавиатуру Callback-кнопку.
     *
     * При нажатии на кнопку бот получает событие `message_callback` с указанным $payload.
     *
     * @param non-empty-string $text Видимый текст кнопки (maxLength: 128).
     * @param non-empty-string $payload Токен кнопки (maxLength: 1024).
     * @return $this
     */
    public function addCallbackButton(string $text, string $payload): self
    {
        return $this->addButton(['type' => 'callback', 'text' => $text, 'payload' => $payload]);
    }

    /**
     * Добавляет в клавиатуру кнопку, которая копирует текст в буфер обмена.
     *
     * @param non-empty-string $text Видимый текст кнопки (maxLength: 128).
     * @param non-empty-string $payload Текст, который копируется в буфер обмена после нажатия на кнопку
     *     (maxLength: 1024).
     * @return $this
     */
    public function addClipboardButton(string $text, string $payload): self
    {
        return $this->addButton(['type' => 'clipboard', 'text' => $text, 'payload' => $payload]);
    }

    /**
     * Прикрепляет карточку контакта к сообщению.
     *
     * Нужно передать хотя бы один из параметров.
     *
     * @param int|null $contactId ID пользователя, если он зарегистрирован в МАКС.
     * @param non-empty-string|null $vcfInfo Полная информация о контакте в формате VCF (minLength: 20).
     * @return $this
     */
    public function addContact(?int $contactId = null, ?string $vcfInfo = null): self
    {
        if ($contactId === null && $vcfInfo === null) {
            throw new InvalidArgumentException('Either contactId or vcfInfo must be set.');
        }

        $payload = [];
        if ($contactId !== null) {
            $payload['contact_id'] = $contactId;
        }
        if ($vcfInfo !== null) {
            $payload['vcf_info'] = $vcfInfo;
        }

        $this->attachments[] = ['type' => 'contact', 'payload' => $payload];

        return $this;
    }

    /**
     * Прикрепляет файл к сообщению.
     *
     * Должен быть единственным вложением в сообщении.
     *
     * @param non-empty-string $token Токен загруженного файла.
     * @return $this
     */
    public function addFile(string $token): self
    {
        $this->attachments[] = ['type' => 'file', 'payload' => ['token' => $token]];

        return $this;
    }

    /**
     * Прикрепляет изображение к сообщению по токену.
     *
     * @param non-empty-string $token Токен загруженного или уже отправленного изображения.
     * @return $this
     */
    public function addImage(string $token): self
    {
        $this->attachments[] = ['type' => 'image', 'payload' => ['token' => $token]];

        return $this;
    }

    /**
     * Прикрепляет изображение к сообщению по внешнему URL.
     *
     * @param non-empty-string $url Любой внешний URL изображения.
     * @return $this
     */
    public function addImageByUrl(string $url): self
    {
        $this->attachments[] = ['type' => 'image', 'payload' => ['url' => $url]];

        return $this;
    }

    /**
     * Переносит следующую кнопку клавиатуры в новый ряд.
     *
     * Клавиатура содержит до 30 рядов, в ряду — до 7 кнопок (до 3, если это кнопки-ссылки, кнопки запуска
     * мини-приложения, запроса контакта или геолокации).
     *
     * @return $this
     */
    public function addKeyboardNewRow(): self
    {
        if ($this->keyboard) {
            $this->keyboardNewRow = true;
        }

        return $this;
    }

    /**
     * Добавляет в клавиатуру кнопку-ссылку.
     *
     * @param non-empty-string $text Видимый текст кнопки (maxLength: 128).
     * @param non-empty-string $url URL кнопки (minLength: 4, maxLength: 2048).
     * @return $this
     */
    public function addLinkButton(string $text, string $url): self
    {
        return $this->addButton(['type' => 'link', 'text' => $text, 'url' => $url]);
    }

    /**
     * Прикрепляет координаты локации к сообщению.
     *
     * @param float $latitude Широта.
     * @param float $longitude Долгота.
     * @return $this
     */
    public function addLocation(float $latitude, float $longitude): self
    {
        $this->attachments[] = ['type' => 'location', 'latitude' => $latitude, 'longitude' => $longitude];

        return $this;
    }

    /**
     * Добавляет в клавиатуру кнопку сообщения.
     *
     * При нажатии текст кнопки отправляется в чат от имени пользователя.
     *
     * @param non-empty-string $text Текст кнопки, который будет отправлен в чат (maxLength: 128).
     * @return $this
     */
    public function addMessageButton(string $text): self
    {
        return $this->addButton(['type' => 'message', 'text' => $text]);
    }

    /**
     * Добавляет в клавиатуру кнопку запуска мини-приложения.
     *
     * Мини-приложение задаётся одним из параметров $webApp или $contactId.
     *
     * @param non-empty-string $text Видимый текст кнопки (maxLength: 128).
     * @param non-empty-string|null $webApp Публичное имя (username) бота или ссылка на него,
     *     чьё мини-приложение надо запустить (minLength: 5).
     * @param int|null $contactId ID бота, чьё мини-приложение надо запустить.
     * @param non-empty-string|null $payload Параметр запуска, который будет передан в `initData` мини-приложения.
     * @return $this
     */
    public function addOpenAppButton(
        string $text,
        ?string $webApp = null,
        ?int $contactId = null,
        ?string $payload = null
    ): self {
        $button = ['type' => 'open_app', 'text' => $text];
        if ($webApp !== null) {
            $button['web_app'] = $webApp;
        }
        if ($contactId !== null) {
            $button['contact_id'] = $contactId;
        }
        if ($payload !== null) {
            $button['payload'] = $payload;
        }

        return $this->addButton($button);
    }

    /**
     * Добавляет в клавиатуру кнопку запроса контакта.
     *
     * @param non-empty-string $text Видимый текст кнопки (maxLength: 128).
     * @return $this
     */
    public function addRequestContactButton(string $text): self
    {
        return $this->addButton(['type' => 'request_contact', 'text' => $text]);
    }

    /**
     * Добавляет в клавиатуру кнопку запроса геолокации.
     *
     * @param non-empty-string $text Видимый текст кнопки (maxLength: 128).
     * @param bool $quick Если `true`, местоположение отправляется без запроса подтверждения у пользователя.
     * @return $this
     */
    public function addRequestGeoLocationButton(string $text, bool $quick = false): self
    {
        return $this->addButton(['type' => 'request_geo_location', 'text' => $text, 'quick' => $quick]);
    }

    /**
     * Прикрепляет предпросмотр контента по внешнему URL.
     *
     * @param non-empty-string $url URL, прикрепляемый к сообщению в качестве предпросмотра.
     * @param non-empty-string|null $token Токен вложения.
     * @return $this
     */
    public function addShare(string $url, ?string $token = null): self
    {
        $payload = ['url' => $url];
        if ($token !== null) {
            $payload['token'] = $token;
        }

        $this->attachments[] = ['type' => 'share', 'payload' => $payload];

        return $this;
    }

    /**
     * Прикрепляет стикер к сообщению.
     *
     * Должен быть единственным вложением в сообщении. В одном запросе можно отправить только один стикер.
     *
     * @param non-empty-string $code Код стикера.
     * @return $this
     */
    public function addSticker(string $code): self
    {
        $this->attachments[] = ['type' => 'sticker', 'payload' => ['code' => $code]];

        return $this;
    }

    /**
     * Прикрепляет видео к сообщению.
     *
     * @param non-empty-string $token Токен загруженного видео.
     * @return $this
     */
    public function addVideo(string $token): self
    {
        $this->attachments[] = ['type' => 'video', 'payload' => ['token' => $token]];

        return $this;
    }

    /**
     * Очищает сообщение: текст, формат, вложения, клавиатуру и настройки отправки.
     *
     * Токен, способ выполнения запросов, базовый адрес и задержки повторов сохраняются.
     *
     * @return $this
     */
    public function reset(): self
    {
        $this->attachments = [];
        $this->disableLinkPreview = false;
        $this->format = null;
        $this->keyboard = [];
        $this->keyboardNewRow = false;
        $this->notify = true;
        $this->text = '';

        return $this;
    }

    /**
     * Отправляет сообщение в чат.
     *
     * @param int $chatId ID чата.
     * @param string|null $message Текст сообщения (maxLength: 4000). Если задан, заменяет текст из
     *     {@see setText()} только для этой отправки.
     * @param string|null $format Разметка текста: {@see FORMAT_HTML} или {@see FORMAT_MARKDOWN}.
     *     Если задана, заменяет формат из {@see setFormat()} только для этой отправки.
     * @return array{
     *     body: array{
     *         mid: non-empty-string,
     *         seq: int,
     *         text: string,
     *         attachments?: list<array<string, mixed>>,
     *         markup?: list<array<string, mixed>>
     *     },
     *     recipient: array{
     *         chat_id: int,
     *         chat_type: 'channel'|'chat'|'dialog',
     *         user_id?: int
     *     },
     *     sender?: array{
     *         user_id: int,
     *         first_name: non-empty-string,
     *         is_bot: bool,
     *         last_activity_time?: int,
     *         last_name?: non-empty-string,
     *         username?: non-empty-string
     *     },
     *     timestamp: int,
     *     url?: non-empty-string
     * } Созданное сообщение в виде объекта {@link https://dev.max.ru/docs-api/objects/Message Message}.
     * @throws ApiException Если API Max вернул ошибку.
     * @throws TransportException Если ответ сервера не получен.
     * @throws SenderException Если ответ сервера не удалось разобрать.
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    public function sendToChat(int $chatId, ?string $message = null, ?string $format = null): array
    {
        return $this->send(['chat_id' => $chatId], $message, $format);
    }

    /**
     * Отправляет сообщение пользователю.
     *
     * @param int $userId ID пользователя.
     * @param string|null $message Текст сообщения (maxLength: 4000). Если задан, заменяет текст из
     *     {@see setText()} только для этой отправки.
     * @param string|null $format Разметка текста: {@see FORMAT_HTML} или {@see FORMAT_MARKDOWN}.
     *     Если задана, заменяет формат из {@see setFormat()} только для этой отправки.
     * @return array{
     *     body: array{
     *         mid: non-empty-string,
     *         seq: int,
     *         text: string,
     *         attachments?: list<array<string, mixed>>,
     *         markup?: list<array<string, mixed>>
     *     },
     *     recipient: array{
     *         chat_id: int,
     *         chat_type: 'channel'|'chat'|'dialog',
     *         user_id?: int
     *     },
     *     sender?: array{
     *         user_id: int,
     *         first_name: non-empty-string,
     *         is_bot: bool,
     *         last_activity_time?: int,
     *         last_name?: non-empty-string,
     *         username?: non-empty-string
     *     },
     *     timestamp: int,
     *     url?: non-empty-string
     * } Созданное сообщение в виде объекта {@link https://dev.max.ru/docs-api/objects/Message Message}.
     * @throws ApiException Если API Max вернул ошибку.
     * @throws TransportException Если ответ сервера не получен.
     * @throws SenderException Если ответ сервера не удалось разобрать.
     * @psalm-suppress MoreSpecificReturnType, LessSpecificReturnStatement
     */
    public function sendToUser(int $userId, ?string $message = null, ?string $format = null): array
    {
        return $this->send(['user_id' => $userId], $message, $format);
    }

    /**
     * @param list<positive-int>|null $attachmentRetryAttempts Задержки перед повтором отправки, если вложение
     *     ещё не обработано сервером (`attachment.not.ready`), в миллисекундах.
     *     `null` — использовать задержки из {@see setRetryAttempts()}, `[]` — не повторять отправку.
     * @return $this
     */
    public function setAttachmentRetryAttempts(?array $attachmentRetryAttempts): self
    {
        $this->attachmentRetryAttempts = $attachmentRetryAttempts;

        return $this;
    }

    /**
     * @param bool $disableLinkPreview Если `true`, сервер не будет генерировать превью для ссылок в тексте сообщения.
     * @return $this
     */
    public function setDisableLinkPreview(bool $disableLinkPreview = true): self
    {
        $this->disableLinkPreview = $disableLinkPreview;

        return $this;
    }

    /**
     * @param string|null $format Разметка текста сообщения: {@see FORMAT_HTML} или {@see FORMAT_MARKDOWN}.
     *     `null` — текст без разметки. Подробнее —
     *     {@link https://dev.max.ru/docs-api/use-cases/sending-messages/text-formatting в разделе «Форматирование»}.
     * @return $this
     */
    public function setFormat(?string $format): self
    {
        $this->format = self::validateFormat($format);

        return $this;
    }

    /**
     * @param bool $notify Если `false`, участники чата не получат push-уведомления (по умолчанию `true`).
     *     Для каналов нужно оставлять `true`: каналы не подразумевают отправку постов без push-уведомлений.
     * @return $this
     */
    public function setNotify(bool $notify): self
    {
        $this->notify = $notify;

        return $this;
    }

    /**
     * @param list<positive-int> $retryAttempts Задержки перед повтором отправки при временной ошибке
     *     в миллисекундах: число элементов — число повторов. `[]` — не повторять отправку.
     *     По умолчанию `[1000, 2000, 4000, 8000, 15000]`.
     * @return $this
     */
    public function setRetryAttempts(array $retryAttempts): self
    {
        $this->retryAttempts = $retryAttempts;

        return $this;
    }

    /**
     * @param string $text Текст сообщения (maxLength: 4000). Пустая строка — сообщение без текста,
     *     например из одного стикера или вложения.
     * @return $this
     */
    public function setText(string $text): self
    {
        $this->text = $text;

        return $this;
    }

    /**
     * Добавляет кнопку в текущий ряд клавиатуры или в новый ряд после {@see addKeyboardNewRow()}.
     *
     * @param array<string, mixed> $button Кнопка.
     * @return $this
     */
    private function addButton(array $button): self
    {
        if (!$this->keyboard || $this->keyboardNewRow) {
            if (count($this->keyboard) >= self::MAX_KEYBOARD_ROWS) {
                throw new LogicException('Keyboard cannot have more than ' . self::MAX_KEYBOARD_ROWS . ' rows.');
            }

            $this->keyboard[] = [$button];
            $this->keyboardNewRow = false;

            return $this;
        }

        /** @psalm-suppress UnsupportedReferenceUsage */
        $lastRow = &$this->keyboard[count($this->keyboard) - 1];

        if (count($lastRow) >= self::MAX_BUTTONS_IN_ROW) {
            throw new LogicException('Keyboard row cannot have more than ' . self::MAX_BUTTONS_IN_ROW . ' buttons.');
        }

        $lastRow[] = $button;

        return $this;
    }

    /**
     * Выполняет запрос к API.
     *
     * @param non-empty-string $url Адрес запроса.
     * @param string $json Тело запроса.
     * @return array<string, mixed> Созданное сообщение.
     * @throws ApiException Если API Max вернул ошибку.
     * @throws TransportException Если ответ сервера не получен.
     * @throws SenderException Если ответ сервера не удалось разобрать.
     */
    private function request(string $url, string $json): array
    {
        [$httpCode, $responseBody] = $this->transport->post(
            $url,
            [
                'Accept' => 'application/json',
                'Authorization' => $this->token,
                'Content-Type' => 'application/json; charset=utf-8',
            ],
            $json,
        );

        /** @var mixed $data */
        $data = json_decode($responseBody, true);

        if ($httpCode < 200 || $httpCode >= 300) {
            /** @var mixed $errorCode */
            $errorCode = is_array($data) ? $data['code'] ?? null : null;
            /** @var mixed $errorMessage */
            $errorMessage = is_array($data) ? $data['message'] ?? null : null;

            throw new ApiException(
                $httpCode,
                is_string($errorCode) ? $errorCode : null,
                is_string($errorMessage) && $errorMessage !== '' ? $errorMessage : "HTTP error $httpCode.",
            );
        }

        if (!is_array($data) || !isset($data['message']) || !is_array($data['message'])) {
            throw new SenderException('Unexpected API response: ' . $responseBody);
        }

        /** @var array<string, mixed> */
        return $data['message'];
    }

    /**
     * @param array{chat_id: int}|array{user_id: int} $query Получатель сообщения.
     * @param string|null $message Текст сообщения для этой отправки.
     * @param string|null $format Разметка текста для этой отправки.
     * @return array<string, mixed> Созданное сообщение.
     */
    private function send(array $query, ?string $message, ?string $format): array
    {
        $text = $message ?? $this->text;
        $format = $format !== null ? self::validateFormat($format) : $this->format;

        $attachments = $this->attachments;
        if ($this->keyboard) {
            $attachments[] = ['type' => 'inline_keyboard', 'payload' => ['buttons' => $this->keyboard]];
        }

        if ($text === '' && !$attachments) {
            throw new LogicException('Message must have text or attachments.');
        }

        $body = ['text' => $text];
        if ($attachments) {
            $body['attachments'] = $attachments;
        }
        $body['notify'] = $this->notify;
        if ($format !== null) {
            $body['format'] = $format;
        }

        if ($this->disableLinkPreview) {
            $query['disable_link_preview'] = 'true';
        }

        try {
            $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('Failed to encode message to JSON: ' . $e->getMessage(), 0, $e);
        }

        $url = $this->baseUrl . '/messages?' . http_build_query($query);
        $retryAttempts = $this->retryAttempts;
        $attachmentRetryAttempts = $this->attachmentRetryAttempts ?? $this->retryAttempts;

        while (true) {
            try {
                return $this->request($url, $json);
            } catch (TransportException $e) {
                $delay = array_shift($retryAttempts);
            } catch (ApiException $e) {
                if ($e->getHttpCode() === 400 && $e->getErrorCode() === 'attachment.not.ready') {
                    $delay = array_shift($attachmentRetryAttempts);
                } elseif (in_array($e->getHttpCode(), self::RETRY_HTTP_CODES, true)) {
                    $delay = array_shift($retryAttempts);
                } else {
                    throw $e;
                }
            }

            if ($delay === null) {
                throw $e;
            }

            usleep($delay * 1000);
        }
    }

    /**
     * @param string|null $format Разметка текста.
     * @return self::FORMAT_*|null Проверенная разметка текста.
     */
    private static function validateFormat(?string $format): ?string
    {
        if ($format !== null && !in_array($format, [self::FORMAT_HTML, self::FORMAT_MARKDOWN], true)) {
            throw new InvalidArgumentException("Unsupported message format: $format.");
        }

        return $format;
    }
}
