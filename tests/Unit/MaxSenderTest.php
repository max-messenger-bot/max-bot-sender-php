<?php

declare(strict_types=1);

namespace MaxMessenger\Sender\Tests\Unit;

use InvalidArgumentException;
use LogicException;
use MaxMessenger\Sender\Exception\ApiException;
use MaxMessenger\Sender\Exception\SenderException;
use MaxMessenger\Sender\Exception\TransportException;
use MaxMessenger\Sender\MaxSender;
use MaxMessenger\Sender\Tests\Fake\FakeTransport;
use PHPUnit\Framework\TestCase;

use function json_encode;

use const JSON_THROW_ON_ERROR;

final class MaxSenderTest extends TestCase
{
    public function testAddAttachments(): void
    {
        $transport = new FakeTransport();

        (new MaxSender('token', $transport))
            ->addAudio('audio-token')
            ->addContact(42, 'BEGIN:VCARD...END:VCARD')
            ->addContact(43)
            ->addFile('file-token')
            ->addImage('image-token')
            ->addImageByUrl('https://example.com/a.png')
            ->addLocation(55.75, 37.62)
            ->addShare('https://example.com')
            ->addShare('https://example.com/page', 'share-token')
            ->addSticker('sticker-code')
            ->addVideo('video-token')
            ->sendToChat(1);

        $this->assertSame([
            ['type' => 'audio', 'payload' => ['token' => 'audio-token']],
            ['type' => 'contact', 'payload' => ['contact_id' => 42, 'vcf_info' => 'BEGIN:VCARD...END:VCARD']],
            ['type' => 'contact', 'payload' => ['contact_id' => 43]],
            ['type' => 'file', 'payload' => ['token' => 'file-token']],
            ['type' => 'image', 'payload' => ['token' => 'image-token']],
            ['type' => 'image', 'payload' => ['url' => 'https://example.com/a.png']],
            ['type' => 'location', 'latitude' => 55.75, 'longitude' => 37.62],
            ['type' => 'share', 'payload' => ['url' => 'https://example.com']],
            ['type' => 'share', 'payload' => ['url' => 'https://example.com/page', 'token' => 'share-token']],
            ['type' => 'sticker', 'payload' => ['code' => 'sticker-code']],
            ['type' => 'video', 'payload' => ['token' => 'video-token']],
        ], $transport->getJsonBody()['attachments']);
    }

    public function testApiErrorWithJsonBody(): void
    {
        $transport = new FakeTransport(400, '{"code":"proto.payload","message":"Invalid request payload"}');
        $sender = new MaxSender('token', $transport);

        try {
            $sender->sendToChat(1, 'Text');
            $this->fail('ApiException was not thrown.');
        } catch (ApiException $e) {
            $this->assertSame(400, $e->getHttpCode());
            $this->assertSame('proto.payload', $e->getErrorCode());
            $this->assertSame('Invalid request payload', $e->getMessage());
            $this->assertSame(1, $transport->requestCount);
        }
    }

    public function testApiErrorWithoutJsonBody(): void
    {
        $sender = (new MaxSender('token', new FakeTransport(502, '<html>Bad Gateway</html>')))
            ->setRetryAttempts([]);

        try {
            $sender->sendToChat(1, 'Text');
            $this->fail('ApiException was not thrown.');
        } catch (ApiException $e) {
            $this->assertSame(502, $e->getHttpCode());
            $this->assertNull($e->getErrorCode());
            $this->assertSame('HTTP error 502.', $e->getMessage());
        }
    }

    public function testAttachmentNotReadyRetry(): void
    {
        $notReady
            = [400, '{"code":"attachment.not.ready","message":"Key: errors.process.attachment.file.not.processed"}'];
        $transport = new FakeTransport();
        $transport->queue = [$notReady, $notReady];

        $result = (new MaxSender('token', $transport))
            ->setRetryAttempts([])
            ->setAttachmentRetryAttempts([1, 1])
            ->addFile('file-token')
            ->sendToChat(1);

        $this->assertSame(FakeTransport::MESSAGE, $result);
        $this->assertSame(3, $transport->requestCount);
    }

    public function testAttachmentNotReadyRetryExhausted(): void
    {
        $transport = new FakeTransport(400, '{"code":"attachment.not.ready","message":"Not ready"}');
        $sender = (new MaxSender('token', $transport))
            ->setRetryAttempts([1, 1])
            ->addFile('file-token');

        try {
            $sender->sendToChat(1);
            $this->fail('ApiException was not thrown.');
        } catch (ApiException $e) {
            $this->assertSame('attachment.not.ready', $e->getErrorCode());
            $this->assertSame(3, $transport->requestCount);
        }
    }

    public function testAttachmentWithoutText(): void
    {
        $transport = new FakeTransport();

        (new MaxSender('token', $transport))
            ->addSticker('sticker-code')
            ->sendToChat(1);

        $this->assertSame(
            [
                'text' => '',
                'attachments' => [['type' => 'sticker', 'payload' => ['code' => 'sticker-code']]],
                'notify' => true,
            ],
            $transport->getJsonBody(),
        );
    }

    public function testBaseUrl(): void
    {
        $transport = new FakeTransport();

        (new MaxSender('token', $transport, 'https://api.example.com/'))->sendToChat(1, 'Text');

        $this->assertSame('https://api.example.com/messages?chat_id=1', $transport->url);
    }

    public function testContactAttachmentRequiresData(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new MaxSender('token', new FakeTransport()))->addContact();
    }

    public function testEmptyMessage(): void
    {
        $this->expectException(LogicException::class);

        (new MaxSender('token', new FakeTransport()))->sendToChat(1);
    }

    public function testEmptyToken(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MaxSender('');
    }

    public function testInvalidFormat(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new MaxSender('token', new FakeTransport()))->setFormat('bbcode');
    }

    public function testInvalidFormatInSend(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new MaxSender('token', new FakeTransport()))->sendToChat(1, 'Text', 'bbcode');
    }

    public function testKeyboard(): void
    {
        $transport = new FakeTransport();

        (new MaxSender('token', $transport))
            ->setText('Choose')
            ->addCallbackButton('Yes', 'answer:yes')
            ->addCallbackButton('No', 'answer:no')
            ->addKeyboardNewRow()
            ->addClipboardButton('Copy', 'PROMO2026')
            ->addMessageButton('Hello')
            ->addKeyboardNewRow()
            ->addLinkButton('Site', 'https://example.com')
            ->addOpenAppButton('App', 'my_bot', 42, 'start')
            ->addOpenAppButton('Bot app', null, 43)
            ->addKeyboardNewRow()
            ->addRequestContactButton('Contact')
            ->addRequestGeoLocationButton('Location')
            ->addRequestGeoLocationButton('Quick location', true)
            ->addSticker('sticker-code')
            ->sendToChat(1);

        $this->assertSame([
            ['type' => 'sticker', 'payload' => ['code' => 'sticker-code']],
            [
                'type' => 'inline_keyboard',
                'payload' => [
                    'buttons' => [
                        [
                            ['type' => 'callback', 'text' => 'Yes', 'payload' => 'answer:yes'],
                            ['type' => 'callback', 'text' => 'No', 'payload' => 'answer:no'],
                        ],
                        [
                            ['type' => 'clipboard', 'text' => 'Copy', 'payload' => 'PROMO2026'],
                            ['type' => 'message', 'text' => 'Hello'],
                        ],
                        [
                            ['type' => 'link', 'text' => 'Site', 'url' => 'https://example.com'],
                            [
                                'type' => 'open_app',
                                'text' => 'App',
                                'web_app' => 'my_bot',
                                'contact_id' => 42,
                                'payload' => 'start',
                            ],
                            ['type' => 'open_app', 'text' => 'Bot app', 'contact_id' => 43],
                        ],
                        [
                            ['type' => 'request_contact', 'text' => 'Contact'],
                            ['type' => 'request_geo_location', 'text' => 'Location', 'quick' => false],
                            ['type' => 'request_geo_location', 'text' => 'Quick location', 'quick' => true],
                        ],
                    ],
                ],
            ],
        ], $transport->getJsonBody()['attachments']);
    }

    public function testKeyboardNewRowBeforeFirstButton(): void
    {
        $transport = new FakeTransport();

        (new MaxSender('token', $transport))
            ->addKeyboardNewRow()
            ->addMessageButton('Hello')
            ->sendToChat(1);

        $this->assertSame(
            [['type' => 'inline_keyboard', 'payload' => ['buttons' => [[['type' => 'message', 'text' => 'Hello']]]]]],
            $transport->getJsonBody()['attachments'],
        );
    }

    public function testKeyboardRowLimit(): void
    {
        $sender = new MaxSender('token', new FakeTransport());
        for ($i = 0; $i < MaxSender::MAX_BUTTONS_IN_ROW; $i++) {
            $sender->addMessageButton("Button $i");
        }

        $this->expectException(LogicException::class);

        $sender->addMessageButton('Extra');
    }

    public function testKeyboardRowsLimit(): void
    {
        $sender = new MaxSender('token', new FakeTransport());
        for ($i = 0; $i < MaxSender::MAX_KEYBOARD_ROWS; $i++) {
            $sender->addMessageButton("Button $i")->addKeyboardNewRow();
        }

        $this->expectException(LogicException::class);

        $sender->addMessageButton('Extra');
    }

    public function testMessageAndFormatOverrideOnlyOneSend(): void
    {
        $transport = new FakeTransport();
        $sender = (new MaxSender('token', $transport))
            ->setText('Stored')
            ->setFormat(MaxSender::FORMAT_MARKDOWN);

        $sender->sendToChat(1, '<b>Override</b>', MaxSender::FORMAT_HTML);
        $this->assertSame(
            ['text' => '<b>Override</b>', 'notify' => true, 'format' => 'html'],
            $transport->getJsonBody(),
        );

        $sender->sendToChat(1);
        $this->assertSame(
            ['text' => 'Stored', 'notify' => true, 'format' => 'markdown'],
            $transport->getJsonBody(),
        );
    }

    public function testNetworkErrorRetry(): void
    {
        $transport = new FakeTransport();
        $transport->queue = [new TransportException('Operation timed out'), [500, ''], [429, '']];

        $result = (new MaxSender('token', $transport))
            ->setRetryAttempts([1, 1, 1])
            ->sendToChat(1, 'Text');

        $this->assertSame(FakeTransport::MESSAGE, $result);
        $this->assertSame(4, $transport->requestCount);
    }

    public function testNetworkErrorRetryExhausted(): void
    {
        $transport = new FakeTransport();
        $transport->queue = [new TransportException('Timeout'), new TransportException('Timeout')];
        $sender = (new MaxSender('token', $transport))->setRetryAttempts([1]);

        try {
            $sender->sendToChat(1, 'Text');
            $this->fail('TransportException was not thrown.');
        } catch (TransportException $e) {
            $this->assertSame(2, $transport->requestCount);
        }
    }

    public function testReset(): void
    {
        $transport = new FakeTransport();
        $sender = (new MaxSender('token', $transport))
            ->setText('Text')
            ->setFormat(MaxSender::FORMAT_HTML)
            ->setNotify(false)
            ->setDisableLinkPreview()
            ->addSticker('code')
            ->addMessageButton('Hello')
            ->reset();

        $result = $sender->sendToUser(2, 'New');

        $this->assertSame(FakeTransport::MESSAGE, $result);
        $this->assertSame('https://platform-api2.max.ru/messages?user_id=2', $transport->url);
        $this->assertSame(['text' => 'New', 'notify' => true], $transport->getJsonBody());
    }

    public function testSendToChat(): void
    {
        $message = [
            'body' => ['mid' => 'mid.123', 'seq' => 115, 'text' => 'Привет'],
            'recipient' => ['chat_id' => -100, 'chat_type' => 'channel'],
            'timestamp' => 1760000000000,
            'url' => 'https://max.ru/c/-100/AZ',
        ];
        $transport = new FakeTransport(200, json_encode(['message' => $message], JSON_THROW_ON_ERROR));

        $result = (new MaxSender('secret-token', $transport))->sendToChat(-100, 'Привет');

        $this->assertSame($message, $result);
        $this->assertSame('https://platform-api2.max.ru/messages?chat_id=-100', $transport->url);
        $this->assertSame([
            'Accept' => 'application/json',
            'Authorization' => 'secret-token',
            'Content-Type' => 'application/json; charset=utf-8',
        ], $transport->headers);
        $this->assertSame('{"text":"Привет","notify":true}', $transport->body);
    }

    public function testSendToUserWithSettings(): void
    {
        $transport = new FakeTransport();

        (new MaxSender('token', $transport))
            ->setText('**Text**')
            ->setFormat(MaxSender::FORMAT_MARKDOWN)
            ->setNotify(false)
            ->setDisableLinkPreview()
            ->sendToUser(7);

        $this->assertSame('https://platform-api2.max.ru/messages?user_id=7&disable_link_preview=true', $transport->url);
        $this->assertSame(
            ['text' => '**Text**', 'notify' => false, 'format' => 'markdown'],
            $transport->getJsonBody(),
        );
    }

    public function testTemporaryHttpErrorRetry(): void
    {
        $transport = new FakeTransport();
        $transport->queue = [[502, ''], [503, ''], [504, '']];

        $result = (new MaxSender('token', $transport))
            ->setRetryAttempts([1, 1, 1])
            ->sendToChat(1, 'Text');

        $this->assertSame(FakeTransport::MESSAGE, $result);
        $this->assertSame(4, $transport->requestCount);
    }

    public function testUnexpectedResponse(): void
    {
        $this->expectException(SenderException::class);

        (new MaxSender('token', new FakeTransport(200, '{"success":true}')))->sendToChat(1, 'Text');
    }
}
