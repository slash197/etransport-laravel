<?php

declare(strict_types=1);

namespace slash197\eTransport\Laravel\Events;

use slash197\eTransport\Laravel\Outbox\OutboxMessage;
use slash197\eTransport\Response\Outcome;

/**
 * ANAF processed a stored message and found it valid. $message->uit is the UIT of the transport.
 */
final readonly class MessageAccepted
{
    public function __construct(
        public OutboxMessage $message,
        public Outcome $outcome,
    ) {
    }
}
