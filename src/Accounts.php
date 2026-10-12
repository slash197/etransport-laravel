<?php

declare(strict_types=1);

namespace slash197\eTransport\Laravel;

use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use slash197\eTransport\Client;
use slash197\eTransport\Environment;

/**
 * The clients of the accounts the application works with, by account key.
 */
final class Accounts
{
    /** The key of the account configured in config/etransport.php. */
    public const DEFAULT = 'default';

    /** @var array<string, Client> */
    private array $clients = [];

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly TokenProvider $tokens,
        private readonly Environment $environment,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * The token is asked for at every request, never when the client is built.
     */
    public function client(string $account = self::DEFAULT): Client
    {
        return $this->clients[$account] ??= new Client(
            $this->httpClient,
            fn (): string => $this->tokens->token($account),
            $this->environment,
            $this->logger,
        );
    }
}
