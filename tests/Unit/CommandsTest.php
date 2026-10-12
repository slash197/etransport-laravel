<?php

declare(strict_types=1);

namespace slash197\eTransport\Laravel\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use slash197\eTransport\Laravel\Outbox\MessageStatus;
use slash197\eTransport\Laravel\Tests\TestCase;

final class CommandsTest extends TestCase
{
    public function testPollReportsHowManyOutcomesItGot(): void
    {
        $stored = $this->uploaded();
        $this->travel(20)->seconds();
        $this->fakeHttp($this->statusResponse(), $this->listResponse());

        $this->command('etransport:poll')
            ->expectsOutputToContain('Got the outcome of 1 message.')
            ->assertSuccessful();

        self::assertSame(MessageStatus::Accepted, $stored->refresh()->status);
    }

    public function testPollTakesItsLimitFromTheConfiguration(): void
    {
        $this->app()->make('config')->set('etransport.outbox.limit', 1);
        $this->uploaded(id: '5001');
        $this->uploaded(id: '5002');
        $this->travel(20)->seconds();
        $this->fakeHttp($this->statusResponse('in prelucrare'));

        $this->command('etransport:poll')->assertSuccessful();

        self::assertCount(3, $this->requests);
    }

    public function testCheckReadsTheMessageListOfATaxCode(): void
    {
        $this->fakeHttp($this->listResponse($this->record()));

        $this->command('etransport:check', ['cif' => self::CIF])
            ->expectsOutputToContain('ok, 1 message in the last day')
            ->assertSuccessful();

        self::assertSame(['GET ' . self::API . '/lista/1/' . self::CIF], $this->sent());
    }

    public function testCheckFailsWhenAnafRefusesTheCall(): void
    {
        $this->fakeHttp(new Response(401));

        $this->command('etransport:check', ['cif' => self::CIF])
            ->expectsOutputToContain('HTTP 401')
            ->assertFailed();
    }
}
