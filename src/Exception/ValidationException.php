<?php

declare(strict_types=1);

namespace slash197\eTransport\Laravel\Exception;

use slash197\eTransport\Exception\ETransportException;
use slash197\eTransport\Validation\Violations;

/**
 * A message that was not uploaded because it breaks ANAF's schema or rules.
 */
final class ValidationException extends ETransportException
{
    public function __construct(public readonly Violations $violations)
    {
        $first = $violations->all()[0] ?? null;
        $more = count($violations) - 1;

        parent::__construct(
            'The e-Transport message is not valid'
            . ($first === null ? '.' : ': ' . $first->message . ($more > 0 ? " (and {$more} more)" : '')),
        );
    }
}
