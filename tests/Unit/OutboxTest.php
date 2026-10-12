<?php

declare(strict_types=1);

namespace slash197\eTransport\Laravel\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\DB;
use slash197\eTransport\Exception\ApiException;
use slash197\eTransport\Laravel\Exception\ValidationException;
use slash197\eTransport\Laravel\Outbox\MessageStatus;
use slash197\eTransport\Laravel\Outbox\OutboxMessage;
use slash197\eTransport\Laravel\Tests\TestCase;
use slash197\eTransport\Laravel\TokenProvider;
use slash197\eTransport\MessageType;

final class OutboxTest extends TestCase
{
    public function testUploadsAMessageAndStoresItForItsFirstCheck(): void
    {
        $this->freezeSecond();

        $stored = $this->uploaded($this->deletion(), '5063704760');

        self::assertSame(['POST ' . self::API . '/upload/ETRANSP/' . self::CIF . '/2'], $this->sent());
        self::assertSame('Bearer token', $this->requests[0]->getHeaderLine('Authorization'));
        self::assertSame($stored->payload, (string) $this->requests[0]->getBody());

        $stored->refresh();
        self::assertSame(MessageStatus::Processing, $stored->status);
        self::assertSame('default', $stored->account);
        self::assertSame(self::CIF, $stored->cif);
        self::assertSame(MessageType::Stergere, $stored->type);
        self::assertSame(self::UIT, $stored->uit);
        self::assertSame('5063704760', $stored->upload_id);
        self::assertSame(0, $stored->checks);
        self::assertSame(now()->toDateTimeString(), $stored->uploaded_at?->toDateTimeString());
        self::assertSame(now()->addSeconds(20)->toDateTimeString(), $stored->next_check_at?->toDateTimeString());
    }

    public function testTakesAnXmlDocumentBuiltElsewhere(): void
    {
        $stored = $this->uploaded(self::NOTIFICATION, uit: self::UIT);

        self::assertSame(MessageType::Notificare, $stored->type);
        self::assertSame(self::CIF, $stored->cif);
        self::assertSame('REF-1', $stored->reference);
        self::assertSame(self::UIT, $stored->uit);
        self::assertSame(self::NOTIFICATION, (string) $this->requests[0]->getBody());
    }

    public function testTellsACorrectionFromANotification(): void
    {
        $correction = str_replace('<notificare codTipOperatiune="30">', '<notificare codTipOperatiune="30"><corectie uit="' . self::UIT . '"/>', self::NOTIFICATION);

        $stored = $this->uploaded($correction);

        self::assertSame(MessageType::Corectie, $stored->type);
        self::assertSame(self::UIT, $stored->uit);
    }

    public function testUploadsForAnotherTaxCodeAndAccount(): void
    {
        $this->app()->instance(TokenProvider::class, new class () implements TokenProvider {
            public function token(string $account): string
            {
                return "token-of-{$account}";
            }
        });
        $this->fakeHttp($this->uploadResponse());

        $stored = $this->outbox()->upload($this->deletion(), account: 'company:7', cif: '10000009');

        self::assertSame(['POST ' . self::API . '/upload/ETRANSP/10000009/2'], $this->sent());
        self::assertSame('Bearer token-of-company:7', $this->requests[0]->getHeaderLine('Authorization'));
        self::assertSame('company:7', $stored->account);
        self::assertSame('10000009', $stored->cif);
    }

    public function testAnInvalidMessageIsNeitherUploadedNorStored(): void
    {
        try {
            $this->outbox()->upload(str_replace('codTarifar="94036010"', 'codTarifar="x"', self::NOTIFICATION));
            self::fail('The message was taken.');
        } catch (ValidationException $exception) {
            self::assertFalse($exception->violations->isEmpty());
        }

        self::assertSame([], $this->requests);
        self::assertSame(0, OutboxMessage::query()->count());
    }

    public function testValidationCanBeLeftToAnaf(): void
    {
        $this->fakeHttp($this->uploadResponse());

        $stored = $this->outbox()->upload(str_replace('codTarifar="94036010"', 'codTarifar="x"', self::NOTIFICATION), validate: false);

        self::assertSame(MessageStatus::Processing, $stored->status);
    }

    public function testADocumentThatIsNotAnETransportMessageIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->outbox()->upload('<invoice/>', validate: false);
    }

    public function testAFailedUploadIsRecordedAndNotTriedAgain(): void
    {
        $this->fakeHttp(new Response(503));

        try {
            $this->outbox()->upload($this->deletion());
            self::fail('The failure was swallowed.');
        } catch (ApiException $exception) {
            self::assertSame(503, $exception->statusCode);
        }

        $stored = OutboxMessage::query()->sole();
        self::assertSame(MessageStatus::Failed, $stored->status);
        self::assertSame([$exception->getMessage()], $stored->errors);
        self::assertNull($stored->upload_id);
        self::assertNull($stored->next_check_at);
        self::assertNotNull($stored->resolved_at);
        self::assertCount(1, $this->requests);
    }

    public function testKeepsTheSubjectAndTheMetaOfTheApplication(): void
    {
        $subject = $this->uploaded();
        $this->fakeHttp($this->uploadResponse('5002'));

        $stored = $this->outbox()->upload($this->deletion(), subject: $subject, meta: ['user' => 5]);

        $stored->refresh();
        self::assertTrue($subject->is($stored->subject));
        self::assertSame(['user' => 5], $stored->meta);
    }

    public function testTheDocumentIsEncryptedAtRest(): void
    {
        $stored = $this->uploaded(self::NOTIFICATION);

        $raw = DB::table($stored->getTable())->value('payload');

        self::assertIsString($raw);
        self::assertStringNotContainsString('eTransport', $raw);
        self::assertSame(self::NOTIFICATION, $stored->refresh()->payload);
    }
}
