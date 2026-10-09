# Max Messenger Bot Sender для PHP

[![Latest Version](https://img.shields.io/packagist/v/max-messenger-bot/max-bot-sender-php)](https://packagist.org/packages/max-messenger-bot/max-bot-sender-php)
[![PHP Version](https://img.shields.io/packagist/dependency-v/max-messenger-bot/max-bot-sender-php/php)](https://packagist.org/packages/max-messenger-bot/max-bot-sender-php)
[![CI](https://github.com/max-messenger-bot/max-bot-sender-php/actions/workflows/ci.yml/badge.svg)](https://github.com/max-messenger-bot/max-bot-sender-php/actions/workflows/ci.yml)
[![License](https://img.shields.io/packagist/l/max-messenger-bot/max-bot-sender-php)](LICENSE)

Лёгкая библиотека для отправки сообщений от имени бота в мессенджер МАКС. Умеет только одно — отправлять
сообщения (метод API [`POST /messages`](https://dev.max.ru/docs-api/methods/POST/messages)), зато подключается
одной строкой, работает на PHP 7.4+ и не тянет за собой зависимостей.

Подходит для уведомлений: заявки с сайта, алерты мониторинга, отчёты cron-задач.

Если нужно больше — получение событий, команды, обработка нажатий кнопок, загрузка файлов — используйте полный SDK
[max-messenger-bot/max-bot-api-php](https://github.com/max-messenger-bot/max-bot-api-php).

## Содержание

- [Требования](#требования)
- [Установка](#установка)
- [Быстрый старт](#быстрый-старт)
- [Сборка сообщения](#сборка-сообщения)
    - [Текст и форматирование](#текст-и-форматирование)
    - [Вложения](#вложения)
    - [Клавиатура](#клавиатура)
    - [Настройки отправки](#настройки-отправки)
    - [Повторная отправка и очистка](#повторная-отправка-и-очистка)
- [Ответ метода](#ответ-метода)
- [Обработка ошибок](#обработка-ошибок)
- [Повторы при ошибках](#повторы-при-ошибках)
- [Выполнение запросов](#выполнение-запросов)
    - [curl](#curl)
    - [Клиент PSR-18](#клиент-psr-18)
    - [Свой транспорт](#свой-транспорт)
- [Справочник методов](#справочник-методов)

## Требования

- PHP 7.4 или новее;
- `ext-json`;
- `ext-curl` — для встроенного транспорта `CurlTransport`. Не нужен, если запросы выполняет клиент PSR-18.

## Установка

```bash
composer require max-messenger-bot/max-bot-sender-php
```

## Быстрый старт

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use MaxMessenger\Sender\MaxSender;

$sender = new MaxSender('your-access-token');

$sender->sendToUser(12345678, 'Привет!');
$sender->sendToChat(-71234567890123, '<b>Новая заявка</b> с сайта', MaxSender::FORMAT_HTML);
```

Токен бота выдаётся при создании бота на [платформе МАКС для партнёров](https://business.max.ru/).

## Сборка сообщения

Сообщение собирается цепочкой методов `set*` и `add*`, а отправляется методами `sendToChat()` и `sendToUser()`:

```php
use MaxMessenger\Sender\MaxSender;

$sender = new MaxSender('your-access-token');

$sender
    ->setText('**Сервер недоступен**: api.example.com')
    ->setFormat(MaxSender::FORMAT_MARKDOWN)
    ->addImageByUrl('https://example.com/graph.png')
    ->sendToChat($chatId);
```

### Текст и форматирование

Текст задаётся методом `setText()` или параметром `$message` методов отправки. Длина текста — до 4000 символов.
По умолчанию текст пустой (`''`).

Разметка задаётся константами:

| Константа                    | Значение     | Разметка                                                                           |
|------------------------------|--------------|------------------------------------------------------------------------------------|
| `MaxSender::FORMAT_HTML`     | `'html'`     | [HTML](https://dev.max.ru/docs-api/use-cases/sending-messages/text-formatting)     |
| `MaxSender::FORMAT_MARKDOWN` | `'markdown'` | [Markdown](https://dev.max.ru/docs-api/use-cases/sending-messages/text-formatting) |

Без разметки (`null`) текст отправляется как есть. Неизвестный формат отклоняется с `InvalidArgumentException`.

Параметры `$message` и `$format` методов `sendToChat()` и `sendToUser()` действуют только на одну отправку
и не меняют значения, заданные через `setText()` и `setFormat()`:

```php
$sender->setText('Текст по умолчанию');

$sender->sendToUser($userId);                         // «Текст по умолчанию»
$sender->sendToUser($userId, '<i>Другой текст</i>', MaxSender::FORMAT_HTML);
$sender->sendToUser($userId);                         // снова «Текст по умолчанию»
```

### Вложения

| Метод                                | Вложение                                                           |
|--------------------------------------|--------------------------------------------------------------------|
| `addAudio($token)`                   | Аудио по токену. Должно быть единственным вложением                |
| `addContact($contactId, $vcfInfo)`   | Карточка контакта: ID пользователя МАКС и/или данные в формате VCF |
| `addFile($token)`                    | Файл по токену. Должен быть единственным вложением                 |
| `addImage($token)`                   | Изображение по токену                                              |
| `addImageByUrl($url)`                | Изображение по внешнему URL                                        |
| `addLocation($latitude, $longitude)` | Геолокация                                                         |
| `addShare($url, $token)`             | Предпросмотр контента по внешнему URL                              |
| `addSticker($code)`                  | Стикер по коду. Должен быть единственным вложением                 |
| `addVideo($token)`                   | Видео по токену                                                    |

Токены аудио, видео и файлов выдаются при загрузке файла на сервер МАКС. Эта библиотека загрузку не выполняет —
для неё используйте [полный SDK](https://github.com/max-messenger-bot/max-bot-api-php/blob/main/docs/UploadingFiles.md).
Изображение проще всего отправить по внешнему URL:

```php
$sender
    ->setText('Отчёт за день')
    ->addImageByUrl('https://example.com/report.png')
    ->addLocation(55.751244, 37.618423)
    ->sendToChat($chatId);
```

Сообщение без текста допустимо, если в нём есть вложения: текст по умолчанию пустой, поэтому для стикера
или вложения его задавать не нужно. Сообщение без текста и без вложений не отправляется: метод выбросит
`LogicException`.

```php
$sender->addSticker('sticker-code')->sendToChat($chatId);
```

### Клавиатура

Под сообщением можно разместить встроенную клавиатуру. Кнопки добавляются в текущий ряд,
`addKeyboardNewRow()` переносит следующую кнопку в новый ряд:

```php
$sender
    ->setText('Новая заявка №1024')
    ->addCallbackButton('Принять', 'order:1024:accept')
    ->addCallbackButton('Отклонить', 'order:1024:reject')
    ->addKeyboardNewRow()
    ->addLinkButton('Открыть в CRM', 'https://crm.example.com/orders/1024')
    ->sendToChat($chatId);
```

| Метод                                                    | Кнопка                                                               |
|----------------------------------------------------------|----------------------------------------------------------------------|
| `addCallbackButton($text, $payload)`                     | Отправляет боту событие `message_callback` с `$payload`              |
| `addClipboardButton($text, $payload)`                    | Копирует `$payload` в буфер обмена                                   |
| `addLinkButton($text, $url)`                             | Открывает ссылку                                                     |
| `addMessageButton($text)`                                | Отправляет текст кнопки в чат от имени пользователя                  |
| `addOpenAppButton($text, $webApp, $contactId, $payload)` | Запускает мини-приложение бота, заданного `$webApp` или `$contactId` |
| `addRequestContactButton($text)`                         | Запрашивает контакт пользователя                                     |
| `addRequestGeoLocationButton($text, $quick)`             | Запрашивает геолокацию; `$quick = true` — без подтверждения          |

Клавиатура содержит до 30 рядов, в ряду — до 7 кнопок (до 3, если это кнопки `link`, `open_app`,
`request_contact` или `request_geo_location`). Лишний ряд или лишняя кнопка в ряду отклоняются с `LogicException`.

Клавиатура отправляется как последнее вложение сообщения, поэтому сообщение из одной клавиатуры тоже допустимо.
Обрабатывать нажатия на Callback-кнопки эта библиотека не умеет — для этого нужен
[полный SDK](https://github.com/max-messenger-bot/max-bot-api-php/blob/main/docs/ProcessingCallbacks.md).

### Настройки отправки

| Метод                                             | Действие                                                                            |
|---------------------------------------------------|-------------------------------------------------------------------------------------|
| `setDisableLinkPreview(bool $disableLinkPreview)` | `true` — не генерировать превью для ссылок в тексте. По умолчанию превью создаётся  |
| `setNotify(bool $notify)`                         | `false` — участники чата не получат push-уведомление. Для каналов оставляйте `true` |

### Повторная отправка и очистка

После отправки сообщение не очищается, поэтому одно и то же сообщение можно отправить нескольким получателям:

```php
$sender->setText('Плановые работы с 02:00 до 03:00');

foreach ($chatIds as $chatId) {
    $sender->sendToChat($chatId);
}
```

Чтобы собрать новое сообщение, вызовите `reset()`: он очищает текст, формат, вложения, клавиатуру и настройки отправки.
Токен, транспорт, базовый адрес и задержки повторов сохраняются.

## Ответ метода

`sendToChat()` и `sendToUser()` возвращают созданное сообщение в виде массива — объект
[Message](https://dev.max.ru/docs-api/objects/Message) API МАКС:

```php
$message = $sender->sendToChat($chatId, 'Привет');

$mid = $message['body']['mid']; // ID сообщения
```

| Поле                        | Тип                               | Описание                                                |
|-----------------------------|-----------------------------------|---------------------------------------------------------|
| `body.attachments`          | `list<array>`                     | Вложения сообщения. Может отсутствовать                 |
| `body.markup`               | `list<array>`                     | Разметка текста. Может отсутствовать                    |
| `body.mid`                  | `string`                          | ID сообщения                                            |
| `body.seq`                  | `int`                             | Порядковый номер сообщения в чате                       |
| `body.text`                 | `string`                          | Текст сообщения                                         |
| `recipient.chat_id`         | `int`                             | ID чата или канала                                      |
| `recipient.chat_type`       | `'channel'`, `'chat'`, `'dialog'` | Тип чата                                                |
| `recipient.user_id`         | `int`                             | ID получателя в диалоге. Только для диалогов            |
| `sender.first_name`         | `string`                          | Имя бота                                                |
| `sender.is_bot`             | `bool`                            | `true` для бота                                         |
| `sender.last_activity_time` | `int`                             | Время последней активности (Unix-время в миллисекундах) |
| `sender.last_name`          | `string`                          | Фамилия. Для ботов не возвращается                      |
| `sender.user_id`            | `int`                             | ID бота                                                 |
| `sender.username`           | `string`                          | Никнейм бота                                            |
| `timestamp`                 | `int`                             | Время создания сообщения (Unix-время в миллисекундах)   |
| `url`                       | `string`                          | Публичная ссылка на пост. Только для каналов            |

Объекта `sender` нет, если сообщение отправлено от имени канала.
Полная форма массива описана в PHPDoc методов, поэтому IDE и Psalm подсказывают поля и их типы.

## Обработка ошибок

Все исключения отправки наследуют `MaxMessenger\Sender\Exception\SenderException`:

| Исключение           | Когда возникает                                                    |
|----------------------|--------------------------------------------------------------------|
| `ApiException`       | API МАКС вернул ошибку (код HTTP вне `2xx`)                        |
| `SenderException`    | Сервер ответил успешно, но в неожиданном формате                   |
| `TransportException` | Ответ не получен: сетевой сбой, истекло время ожидания, ошибка TLS |

`ApiException` содержит код ответа HTTP и код ошибки API:

```php
use MaxMessenger\Sender\Exception\ApiException;
use MaxMessenger\Sender\Exception\SenderException;

try {
    $sender->sendToUser($userId, 'Привет');
} catch (ApiException $e) {
    $e->getHttpCode();  // 401
    $e->getErrorCode(); // 'verify.token'
    $e->getMessage();   // 'Invalid access_token'
} catch (SenderException $e) {
    // Сетевая ошибка или неожиданный ответ
}
```

Ошибки использования — пустой токен, неизвестный формат, контакт без данных — выбрасывают `InvalidArgumentException`,
а попытка отправить пустое сообщение или переполнить клавиатуру — `LogicException`.

Исключение выбрасывается, только когда исчерпаны [повторы](#повторы-при-ошибках).

## Повторы при ошибках

При временной ошибке отправка повторяется автоматически, как в полном SDK:

| Ошибка                                                                | Задержки повторов              |
|-----------------------------------------------------------------------|--------------------------------|
| `TransportException`: сетевой сбой, истекло время ожидания            | `setRetryAttempts()`           |
| HTTP 429, 500, 502, 503, 504                                          | `setRetryAttempts()`           |
| HTTP 400 с кодом `attachment.not.ready` — вложение ещё обрабатывается | `setAttachmentRetryAttempts()` |

Задержки задаются списком в миллисекундах: сколько элементов, столько повторов. По умолчанию —
`[1000, 2000, 4000, 8000, 15000]`, то есть до пяти повторов за 30 секунд. Пустой список отключает повторы.
`setAttachmentRetryAttempts(null)` (по умолчанию) использует задержки из `setRetryAttempts()`:

```php
$sender
    ->setRetryAttempts([500, 1000, 2000]) // три повтора при временной ошибке
    ->setAttachmentRetryAttempts([]);     // не повторять при attachment.not.ready
```

Обе очереди задержек расходуются независимо, и при каждой отправке начинаются заново.

> Повтор после истечения времени ожидания может привести к дублю: сервер мог принять сообщение, но ответ
> не успел дойти. Если дубль недопустим, отключите повторы (`setRetryAttempts([])`) и обрабатывайте
> `TransportException` сами.

## Выполнение запросов

`MaxSender` выполняет запросы через транспорт — объект с интерфейсом
`MaxMessenger\Sender\Transport\TransportInterface`. Транспорт передаётся вторым параметром конструктора.
Если он не задан, создаётся `CurlTransport` с настройками по умолчанию.

Третий параметр конструктора — базовый адрес API (по умолчанию `MaxSender::BASE_URL`, `https://platform-api2.max.ru`).
Если указан собственный HTTPS-адрес, передайте транспорт с подходящими сертификатами:
по умолчанию `CurlTransport` использует только корневые сертификаты Минцифры.
Для сертификата из системного хранилища можно передать `new CurlTransport(10000, 5000, false)`;
для собственного CA используйте `setCaCertificatePath()` или `setCaCertificateDir()`.

### curl

```php
use MaxMessenger\Sender\MaxSender;
use MaxMessenger\Sender\Transport\CurlTransport;

$transport = new CurlTransport(
    30000, // $timeout: сколько миллисекунд может выполняться запрос, 0 — без ограничения
    5000,  // $connectTimeout: сколько миллисекунд ждать подключения, 0 — сколько угодно
    true,  // $useRussianTrustedCaCertificates: доверять сертификатам Минцифры из пакета
);

$sender = new MaxSender('your-access-token', $transport);
```

По умолчанию: `$timeout` — 10 000 мс, `$connectTimeout` — 5000 мс, `$useRussianTrustedCaCertificates` — `true`.

**Сертификаты.** Сертификат API МАКС выпущен удостоверяющим центром Минцифры, которого нет в большинстве
системных хранилищ доверия. Поэтому `CurlTransport` по умолчанию проверяет сервер по корневым сертификатам
Минцифры, поставляемым с пакетом (`resources/certs/russian_trusted_ca_bundle.pem`). Если сертификаты Минцифры уже
установлены в системе, передайте `false` третьим параметром — будут использованы системные сертификаты.
Включить пакетные сертификаты позже можно методом `useRussianTrustedCaCertificates()`.

Собственные корневые сертификаты задаются файлом или каталогом. `null` возвращает системные сертификаты:

```php
$transport->setCaCertificatePath('/etc/ssl/custom/ca-bundle.pem'); // файл PEM (CURLOPT_CAINFO)
$transport->setCaCertificateDir('/etc/ssl/custom/certs'); // каталог после openssl rehash (CURLOPT_CAPATH)
```

`setCaCertificatePath()` заменяет пакетный набор Минцифры, если он был включён.

**Прокси.** Метод `setProxy()` задаёт прокси-сервер:

```php
$transport->setProxy('http://user:password@proxy.local:3128'); // HTTP-прокси
$transport->setProxy('socks5.local:1080', true);               // SOCKS5
$transport->setProxy();                                        // отключить прокси
```

**Прочие параметры curl** задаются методом `setOption()`. Значение `null` удаляет параметр:

```php
$transport->setOption(CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
```

### Клиент PSR-18

Если в проекте уже есть HTTP-клиент PSR-18, используйте `PsrTransport`.
Нужны также фабрики PSR-17 для запросов и потоков; если клиент сам их реализует, фабрики можно не передавать.
Таймауты, прокси и сертификаты в этом случае настраиваются в самом клиенте.

Guzzle:

```php
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use MaxMessenger\Sender\MaxSender;
use MaxMessenger\Sender\Transport\PsrTransport;

$factory = new HttpFactory();
$sender = new MaxSender('your-access-token', new PsrTransport(new Client(['timeout' => 10]), $factory, $factory));
```

Symfony HttpClient (`Psr18Client` сам реализует фабрики):

```php
use MaxMessenger\Sender\MaxSender;
use MaxMessenger\Sender\Transport\PsrTransport;
use Symfony\Component\HttpClient\Psr18Client;

$sender = new MaxSender('your-access-token', new PsrTransport(new Psr18Client()));
```

Сетевые ошибки клиента PSR-18 (`NetworkExceptionInterface`) превращаются в `TransportException` и повторяются,
исходное исключение доступно через `getPrevious()`.
Прочие ошибки клиента (`ClientExceptionInterface`) не повторяются и пробрасываются как есть.

### Свой транспорт

Достаточно реализовать один метод интерфейса `TransportInterface`:

```php
use MaxMessenger\Sender\Exception\TransportException;
use MaxMessenger\Sender\Transport\TransportInterface;

final class MyTransport implements TransportInterface
{
    public function post(string $url, array $headers, string $body): array
    {
        // Выполнить POST-запрос; при сетевой ошибке выбросить TransportException — запрос будет повторён.

        return [$httpCode, $responseBody];
    }
}
```

## Справочник методов

| Метод                                                                                | Описание                                            |
|--------------------------------------------------------------------------------------|-----------------------------------------------------|
| `__construct(string $token, ?TransportInterface $transport, string $baseUrl)`        | Создаёт отправителя                                 |
| `addAudio(string $token)`                                                            | Прикрепляет аудио                                   |
| `addCallbackButton(string $text, string $payload)`                                   | Добавляет Callback-кнопку                           |
| `addClipboardButton(string $text, string $payload)`                                  | Добавляет кнопку копирования в буфер обмена         |
| `addContact(?int $contactId, ?string $vcfInfo)`                                      | Прикрепляет карточку контакта                       |
| `addFile(string $token)`                                                             | Прикрепляет файл                                    |
| `addImage(string $token)`                                                            | Прикрепляет изображение по токену                   |
| `addImageByUrl(string $url)`                                                         | Прикрепляет изображение по URL                      |
| `addKeyboardNewRow()`                                                                | Переносит следующую кнопку в новый ряд              |
| `addLinkButton(string $text, string $url)`                                           | Добавляет кнопку-ссылку                             |
| `addLocation(float $latitude, float $longitude)`                                     | Прикрепляет геолокацию                              |
| `addMessageButton(string $text)`                                                     | Добавляет кнопку сообщения                          |
| `addOpenAppButton(string $text, ?string $webApp, ?int $contactId, ?string $payload)` | Добавляет кнопку запуска мини-приложения            |
| `addRequestContactButton(string $text)`                                              | Добавляет кнопку запроса контакта                   |
| `addRequestGeoLocationButton(string $text, bool $quick)`                             | Добавляет кнопку запроса геолокации                 |
| `addShare(string $url, ?string $token)`                                              | Прикрепляет предпросмотр контента по URL            |
| `addSticker(string $code)`                                                           | Прикрепляет стикер                                  |
| `addVideo(string $token)`                                                            | Прикрепляет видео                                   |
| `reset()`                                                                            | Очищает сообщение                                   |
| `sendToChat(int $chatId, ?string $message, ?string $format)`                         | Отправляет сообщение в чат                          |
| `sendToUser(int $userId, ?string $message, ?string $format)`                         | Отправляет сообщение пользователю                   |
| `setAttachmentRetryAttempts(?array $attachmentRetryAttempts)`                        | Задаёт задержки повторов при `attachment.not.ready` |
| `setDisableLinkPreview(bool $disableLinkPreview)`                                    | Отключает превью ссылок                             |
| `setFormat(?string $format)`                                                         | Задаёт разметку текста                              |
| `setNotify(bool $notify)`                                                            | Включает или отключает push-уведомление             |
| `setRetryAttempts(array $retryAttempts)`                                             | Задаёт задержки повторов при временной ошибке       |
| `setText(string $text)`                                                              | Задаёт текст сообщения                              |

## Лицензия

[MIT](LICENSE)
