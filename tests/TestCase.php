<?php

declare(strict_types=1);

namespace slash197\eTransport\Laravel\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Orchestra\Testbench\TestCase as Orchestra;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use slash197\eTransport\Laravel\ETransportServiceProvider;
use slash197\eTransport\Laravel\Outbox\Outbox;
use slash197\eTransport\Laravel\Outbox\OutboxMessage;
use slash197\eTransport\Message;

/**
 * All data is made up; the UITs are the samples printed in ANAF's rules file.
 */
abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected const CIF = '10000001';
    protected const UIT = '3V0P0L0P0T3JUW46';
    protected const API = '/test/ETRANSPORT/ws/v1';

    protected const NOTIFICATION = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <eTransport xmlns="mfp:anaf:dgti:eTransport:declaratie:v2" codDeclarant="10000001" refDeclarant="REF-1">
          <notificare codTipOperatiune="30">
            <bunuriTransportate codScopOperatiune="101" codTarifar="94036010" denumireMarfa="Mobilier din lemn" cantitate="12.50" codUnitateMasura="H87" greutateNeta="480.00" greutateBruta="510.25" valoareLeiFaraTva="18250.00"/>
            <partenerComercial codTara="RO" cod="10000002" denumire="Partener Exemplu SRL"/>
            <dateTransport nrVehicul="B100ABC" nrRemorca1="B200ABC" codTaraOrgTransport="RO" codOrgTransport="10000003" denumireOrgTransport="Transportator Exemplu SRL" dataTransport="2026-10-12"/>
            <locStartTraseuRutier>
              <locatie codJudet="12" denumireLocalitate="Cluj-Napoca" denumireStrada="Strada Exemplu" numar="1"/>
            </locStartTraseuRutier>
            <locFinalTraseuRutier>
              <locatie codJudet="40" denumireLocalitate="Bucuresti" denumireStrada="Bulevardul Exemplu" numar="2"/>
            </locFinalTraseuRutier>
            <documenteTransport tipDocument="30" numarDocument="F-100" dataDocument="2026-10-10"/>
          </notificare>
        </eTransport>
        XML;

    /** @var list<RequestInterface> */
    protected array $requests = [];

    private MockHandler $http;

    /**
     * No test reaches the network: every client gets a fake that fails once
     * it runs out of queued responses.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->http = new MockHandler();
        $stack = HandlerStack::create($this->http);
        $stack->push(Middleware::tap(function (RequestInterface $request): void {
            $this->requests[] = $request;
        }));

        $this->app()->instance(ClientInterface::class, new Client(['handler' => $stack]));
    }

    protected function app(): Application
    {
        self::assertNotNull($this->app);

        return $this->app;
    }

    protected function getPackageProviders($app): array
    {
        return [ETransportServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        $app['config']->set('cache.default', 'array');
        $app['config']->set('etransport.token', 'token');
    }

    /**
     * Queues the responses the fake HTTP client answers with next, in order.
     */
    protected function fakeHttp(Response ...$responses): void
    {
        $this->http->append(...array_values($responses));
    }

    /**
     * @param array<string, mixed> $parameters
     */
    protected function command(string $command, array $parameters = []): PendingCommand
    {
        $pending = $this->artisan($command, $parameters);
        self::assertInstanceOf(PendingCommand::class, $pending);

        return $pending;
    }

    /**
     * @param array<mixed> $body
     */
    protected function jsonResponse(array $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($body));
    }

    protected function uploadResponse(string $id = '5001', ?string $uit = null): Response
    {
        return $this->jsonResponse(array_filter(['ExecutionStatus' => 0, 'index_incarcare' => $id, 'UIT' => $uit], static fn (mixed $value): bool => $value !== null));
    }

    protected function statusResponse(string $stare = 'ok'): Response
    {
        return $this->jsonResponse(['stare' => $stare, 'ExecutionStatus' => 0]);
    }

    /**
     * @param array<string, mixed> ...$records
     */
    protected function listResponse(array ...$records): Response
    {
        return $this->jsonResponse(['mesaje' => array_values($records), 'cui' => self::CIF, 'ExecutionStatus' => 0]);
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    protected function record(array $changes = []): array
    {
        return $changes + [
            'uit' => self::UIT,
            'cod_decl' => self::CIF,
            'ref_decl' => 'REF-1',
            'sursa' => 'A',
            'id_incarcare' => '5001',
            'data_creare' => '202610121030',
            'tip' => 'NOT',
            'stare' => 'OK',
        ];
    }

    protected function deletion(): Message
    {
        return Message::stergere(self::CIF, self::UIT);
    }

    protected function outbox(): Outbox
    {
        return $this->app()->make(Outbox::class);
    }

    /**
     * A message ANAF received and has not said anything about yet.
     */
    protected function uploaded(Message|string|null $message = null, string $id = '5001', ?string $uit = null): OutboxMessage
    {
        $this->fakeHttp($this->uploadResponse($id, $uit));

        return $this->outbox()->upload($message ?? $this->deletion());
    }

    /**
     * @return list<string> Method and path of each request sent so far
     */
    protected function sent(): array
    {
        return array_map(
            static fn (RequestInterface $request): string => $request->getMethod() . ' ' . $request->getUri()->getPath(),
            $this->requests,
        );
    }
}
