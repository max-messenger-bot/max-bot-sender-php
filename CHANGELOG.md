# История изменений

Формат основан на [Keep a Changelog](https://keepachangelog.com/ru/1.1.0/),
версии следуют [семантическому версионированию](https://semver.org/lang/ru/).

## [1.0.0] — 2026-10-09

Первый выпуск.

### Добавлено

- Класс `MaxSender` для отправки сообщений в чат (`sendToChat()`) и пользователю (`sendToUser()`).
- Текст и разметка сообщения: `setText()`, `setFormat()`, константы `FORMAT_HTML` и `FORMAT_MARKDOWN`.
- Вложения: аудио, контакт, файл, изображение по токену и по URL, геолокация, предпросмотр ссылки, стикер, видео.
- Встроенная клавиатура: кнопки `callback`, `clipboard`, `link`, `message`, `open_app`, `request_contact`,
  `request_geo_location` и перенос в новый ряд `addKeyboardNewRow()`.
- Настройки отправки: `setDisableLinkPreview()`, `setNotify()`; очистка сообщения методом `reset()`.
- Повтор отправки при сетевых сбоях, таймаутах, HTTP 429, 500, 502, 503, 504 (`setRetryAttempts()`)
  и при неготовом вложении `attachment.not.ready` (`setAttachmentRetryAttempts()`).
- Транспорт `CurlTransport` с таймаутами, прокси, встроенными корневыми сертификатами Минцифры
  и собственными сертификатами (`setCaCertificatePath()`, `setCaCertificateDir()`).
- Транспорт `PsrTransport` для любого клиента PSR-18.
- Исключения `ApiException`, `TransportException` и базовое `SenderException`.
- Поддержка PHP 7.4–8.5.

[1.0.0]: https://github.com/max-messenger-bot/max-bot-sender-php/releases/tag/1.0.0
