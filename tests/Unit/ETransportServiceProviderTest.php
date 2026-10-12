<?php

declare(strict_types=1);

namespace slash197\eTransport\Laravel\Tests\Unit;

use slash197\eTransport\Client;
use slash197\eTransport\Environment;
use slash197\eTransport\Laravel\Accounts;
use slash197\eTransport\Laravel\Facades\ETransport;
use slash197\eTransport\Laravel\Tests\TestCase;
use slash197\eTransport\Laravel\TokenProvider;

final class ETransportServiceProviderTest extends TestCase
{
    public function testTheEnvironmentDefaultsToTest(): void
    {
        self::assertSame(Environment::Test, $this->app()->make(Environment::class));
    }

    public function testTheEnvironmentComesFromTheConfiguration(): void
    {
        $this->app()->make('config')->set('etransport.environment', 'production');
        $this->fakeHttp($this->listResponse());

        $this->app()->make(Client::class)->messages(self::CIF, 1);

        self::assertSame(['GET /prod/ETRANSPORT/ws/v1/lista/1/' . self::CIF], $this->sent());
    }

    public function testAnUnknownEnvironmentIsRefused(): void
    {
        $this->app()->make('config')->set('etransport.environment', 'staging');

        $this->expectException(\InvalidArgumentException::class);
        $this->app()->make(Environment::class);
    }

    public function testTheFacadeIsTheClientOfTheConfiguredToken(): void
    {
        $this->fakeHttp($this->listResponse($this->record()));

        self::assertCount(1, ETransport::messages(self::CIF, 1));
        self::assertSame('Bearer token', $this->requests[0]->getHeaderLine('Authorization'));
    }

    public function testTheConfiguredTokenIsOnlyForTheDefaultAccount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown e-Transport account "company:7"');

        $this->app()->make(Accounts::class)->client('company:7')->messages(self::CIF, 1);
    }

    public function testAMissingTokenIsSaidPlainly(): void
    {
        $this->app()->make('config')->set('etransport.token', null);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Set ETRANSPORT_TOKEN');

        $this->app()->make(Client::class)->messages(self::CIF, 1);
    }

    public function testAnApplicationsTokenProviderIsAskedAtEveryRequest(): void
    {
        $provider = new class () implements TokenProvider {
            public int $asked = 0;

            public function token(string $account): string
            {
                return $account . '-token-' . ++$this->asked;
            }
        };
        $this->app()->instance(TokenProvider::class, $provider);
        $this->fakeHttp($this->listResponse(), $this->listResponse());

        $client = $this->app()->make(Accounts::class)->client('company:7');
        $client->messages(self::CIF, 1);
        $client->messages(self::CIF, 1);

        self::assertSame('Bearer company:7-token-1', $this->requests[0]->getHeaderLine('Authorization'));
        self::assertSame('Bearer company:7-token-2', $this->requests[1]->getHeaderLine('Authorization'));
    }
}
