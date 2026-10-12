<?php

declare(strict_types=1);

namespace slash197\eTransport\Laravel\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use slash197\eTransport\Laravel\Events\MessageAccepted;
use slash197\eTransport\Laravel\Events\MessageGivenUp;
use slash197\eTransport\Laravel\Events\MessageRejected;
use slash197\eTransport\Laravel\Outbox\MessageStatus;
use slash197\eTransport\Laravel\Outbox\OutboxMessage;
use slash197\eTransport\Laravel\Outbox\OutboxPoller;
use slash197\eTransport\Laravel\Tests\TestCase;
use slash197\eTransport\Laravel\TokenProvider;
use slash197\eTransport\PollSchedule;
use slash197\eTransport\Response\OutcomeState;

final class OutboxPollerTest extends TestCase
{
    private const STATUS = 'GET ' . self::API . '/stareMesaj/5001';

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([MessageAccepted::class, MessageRejected::class, MessageGivenUp::class]);
        $this->freezeSecond();
    }

    public function testNothingIsAskedBeforeTheFirstCheckIsDue(): void
    {
        $stored = $this->uploaded();
        $this->travel(19)->seconds();

        self::assertSame(0, $this->poller()->pollDue());
        self::assertCount(1, $this->requests);
        self::assertSame(0, $stored->refresh()->checks);
    }

    public function testAnAcceptedNotificationGetsItsUit(): void
    {
        $stored = $this->uploaded(self::NOTIFICATION);
        $this->travel(20)->seconds();
        $this->fakeHttp($this->statusResponse(), $this->listResponse($this->record()));

        self::assertSame(1, $this->poller()->pollDue());

        self::assertSame([self::STATUS, 'GET ' . self::API . '/lista/1/' . self::CIF], array_slice($this->sent(), 1));
        $stored->refresh();
        self::assertSame(MessageStatus::Accepted, $stored->status);
        self::assertSame(self::UIT, $stored->uit);
        self::assertNull($stored->errors);
        self::assertNull($stored->next_check_at);
        self::assertSame(now()->toDateTimeString(), $stored->resolved_at?->toDateTimeString());
        Event::assertDispatched(
            MessageAccepted::class,
            static fn (MessageAccepted $event): bool => $event->message->is($stored) && $event->outcome->state === OutcomeState::Accepted,
        );
    }

    public function testADeletionIsAcceptedThoughAnafListsNoRecordOfIt(): void
    {
        $stored = $this->uploaded($this->deletion());
        $this->travel(20)->seconds();
        $this->fakeHttp($this->statusResponse(), $this->listResponse());

        self::assertSame(1, $this->poller()->pollDue());
        self::assertSame(MessageStatus::Accepted, $stored->refresh()->status);
    }

    public function testARejectedMessageKeepsAnafsErrors(): void
    {
        $stored = $this->uploaded();
        $this->travel(20)->seconds();
        $this->fakeHttp($this->jsonResponse([
            'Errors' => [['errorMessage' => 'UIT-ul nu poate fi identificat.']],
            'stare' => 'nok',
            'ExecutionStatus' => 1,
        ]));

        self::assertSame(1, $this->poller()->pollDue());

        $stored->refresh();
        self::assertSame(MessageStatus::Rejected, $stored->status);
        self::assertSame(['UIT-ul nu poate fi identificat.'], $stored->errors);
        self::assertStringNotContainsString('UIT-ul', (string) DB::table($stored->getTable())->value('errors'));
        Event::assertDispatched(MessageRejected::class, static fn (MessageRejected $event): bool => $event->message->is($stored));
        Event::assertNotDispatched(MessageAccepted::class);
    }

    public function testAMessageStillInProcessingIsAskedAboutAgainOnSchedule(): void
    {
        $stored = $this->uploaded();
        $uploadedAt = now();
        $this->travel(20)->seconds();
        $this->fakeHttp($this->statusResponse('in prelucrare'), $this->statusResponse('in prelucrare'));

        $this->poller()->pollDue();
        $this->poller()->pollDue();

        $stored->refresh();
        self::assertSame(MessageStatus::Processing, $stored->status);
        self::assertSame(1, $stored->checks);
        self::assertSame($uploadedAt->copy()->addSeconds(70)->toDateTimeString(), $stored->next_check_at?->toDateTimeString());
        self::assertCount(2, $this->requests);

        $this->travel(50)->seconds();
        self::assertSame(0, $this->poller()->pollDue());
        self::assertSame(2, $stored->refresh()->checks);
        Event::assertNothingDispatched();
    }

    public function testChecksMissedWhileNothingPolledAreNotMadeUpFor(): void
    {
        $stored = $this->uploaded();
        $uploadedAt = now();
        $this->travel(10)->minutes();
        $this->fakeHttp($this->statusResponse('in prelucrare'));

        $this->poller()->pollDue();
        $this->poller()->pollDue();

        $stored->refresh();
        self::assertSame(5, $stored->checks);
        self::assertSame($uploadedAt->copy()->addSeconds(1150)->toDateTimeString(), $stored->next_check_at?->toDateTimeString());
        self::assertCount(2, $this->requests);
    }

    public function testGivesUpWhenTheScheduleEnds(): void
    {
        $this->app()->instance(PollSchedule::class, new PollSchedule([10], 10, 25));
        $stored = $this->uploaded();
        $this->fakeHttp($this->statusResponse('in prelucrare'), $this->statusResponse('in prelucrare'));

        $this->travel(10)->seconds();
        $this->poller()->pollDue();
        self::assertSame(MessageStatus::Processing, $stored->refresh()->status);

        $this->travel(10)->seconds();
        self::assertSame(0, $this->poller()->pollDue());

        $stored->refresh();
        self::assertSame(MessageStatus::GivenUp, $stored->status);
        self::assertNotNull($stored->resolved_at);
        Event::assertDispatched(MessageGivenUp::class, static fn (MessageGivenUp $event): bool => $event->message->is($stored));

        $this->travel(1)->hours();
        self::assertSame(0, $this->poller()->pollDue());
        self::assertCount(3, $this->requests);
    }

    public function testACheckThatFailsIsRecordedAndTheNextOneStillMade(): void
    {
        $stored = $this->uploaded();
        $this->travel(20)->seconds();
        $this->fakeHttp(new Response(503), $this->statusResponse(), $this->listResponse());

        self::assertSame(0, $this->poller()->pollDue());
        $stored->refresh();
        self::assertSame(MessageStatus::Processing, $stored->status);
        self::assertNotNull($stored->errors);
        self::assertStringContainsString('HTTP 503', $stored->errors[0]);

        $this->travel(50)->seconds();
        self::assertSame(1, $this->poller()->pollDue());
        $stored->refresh();
        self::assertSame(MessageStatus::Accepted, $stored->status);
        self::assertNull($stored->errors);
    }

    public function testATokenThatCannotBeHadIsReportedToTheApplication(): void
    {
        $stored = $this->uploaded();
        $this->app()->instance(TokenProvider::class, new class () implements TokenProvider {
            public function token(string $account): string
            {
                throw new \InvalidArgumentException('The token was revoked.');
            }
        });
        $this->app()->forgetScopedInstances();
        $reported = [];
        $handler = $this->app()->make(ExceptionHandler::class);
        self::assertInstanceOf(Handler::class, $handler);
        $handler->reportable(static function (\Throwable $exception) use (&$reported): bool {
            $reported[] = $exception->getMessage();

            return false;
        });
        $this->travel(20)->seconds();

        self::assertSame(0, $this->poller()->pollDue());

        self::assertSame(['The token was revoked.'], $reported);
        $stored->refresh();
        self::assertSame(MessageStatus::Processing, $stored->status);
        self::assertSame(['The token was revoked.'], $stored->errors);
    }

    public function testChecksNoMoreMessagesThanTheLimit(): void
    {
        $this->uploaded(id: '5001');
        $this->uploaded(id: '5002');
        $this->travel(20)->seconds();
        $this->fakeHttp($this->statusResponse('in prelucrare'));

        $this->poller()->pollDue(1);

        self::assertSame([1, 0], OutboxMessage::query()->orderBy('id')->pluck('checks')->all());
    }

    public function testALastCheckThatNeverReportedBackIsGivenUpOn(): void
    {
        $stored = $this->uploaded();
        $stored->update(['checks' => 53, 'next_check_at' => null, 'last_check_at' => now()]);

        $this->travel(5)->minutes();
        $this->poller()->pollDue();
        self::assertSame(MessageStatus::Processing, $stored->refresh()->status);

        $this->travel(5)->minutes();
        $this->poller()->pollDue();
        self::assertSame(MessageStatus::GivenUp, $stored->refresh()->status);
        Event::assertDispatched(MessageGivenUp::class);
    }

    private function poller(): OutboxPoller
    {
        return $this->app()->make(OutboxPoller::class);
    }
}
