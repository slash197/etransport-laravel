<?php

declare(strict_types=1);

namespace slash197\eTransport\Laravel;

use Illuminate\Contracts\Config\Repository;

/**
 * The single-account case: the token in config/etransport.php, known as "default".
 */
final class ConfigTokenProvider implements TokenProvider
{
    public function __construct(private readonly Repository $config)
    {
    }

    public function token(string $account): string
    {
        if ($account !== Accounts::DEFAULT) {
            throw new \InvalidArgumentException(
                "Unknown e-Transport account \"{$account}\". Bind your own " . TokenProvider::class . ' to use more than one.',
            );
        }

        $token = $this->config->get('etransport.token');

        if (!is_string($token) || $token === '') {
            throw new \InvalidArgumentException(
                'No e-Transport token is configured. Set ETRANSPORT_TOKEN or bind your own ' . TokenProvider::class . '.',
            );
        }

        return $token;
    }
}
