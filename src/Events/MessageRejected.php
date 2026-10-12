<?php

declare(strict_types=1);

namespace slash197\eTransport\Laravel\Events;

use slash197\eTransport\Laravel\Outbox\OutboxMessage;
use slash197\eTransport\Response\Outcome;

/**
 * ANAF refused a stored message. $message->errors says why.
 */
final readonly class MessageRejected
{
    public function __construct(
        public OutboxMessage $message,
        public Outcome $outcome,
    ) {
    }
}
