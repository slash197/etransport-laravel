<?php

declare(strict_types=1);

namespace slash197\eTransport\Laravel\Events;

use slash197\eTransport\Laravel\Outbox\OutboxMessage;

/**
 * ANAF gave no outcome for a stored message before the poll schedule ended.
 * Whether the message went through is unknown; $message->errors holds the
 * failure of the last check, if it failed.
 */
final readonly class MessageGivenUp
{
    public function __construct(
        public OutboxMessage $message,
    ) {
    }
}
