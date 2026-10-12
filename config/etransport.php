<?php

declare(strict_types=1);

return [

    // "test" or "production"
    'environment' => env('ETRANSPORT_ENVIRONMENT', 'test'),

    // ANAF OAuth access token of the one account the container-bound client
    // works with. Applications that keep a token per company or per user
    // leave this empty and bind their own TokenProvider instead.
    'token' => env('ETRANSPORT_TOKEN'),

    // Messages uploaded through the Outbox and checked by etransport:poll.
    // Set enabled to false if the application keeps its own; no table is
    // created then.
    'outbox' => [
        'enabled' => env('ETRANSPORT_OUTBOX', true),
        'table' => 'etransport_messages',
        // Most messages etransport:poll checks in one run. Every check of a
        // processed message also reads ANAF's message list, which answers
        // 1000 times a day per user.
        'limit' => 100,
    ],

    // Receives request metadata, never message contents; null is the default channel
    'log_channel' => env('ETRANSPORT_LOG_CHANNEL'),

    // Used for the built-in Guzzle client; ignored when the application binds
    // its own Psr\Http\Client\ClientInterface
    'http' => [
        'timeout' => 30,
        'connect_timeout' => 10,
    ],

];
