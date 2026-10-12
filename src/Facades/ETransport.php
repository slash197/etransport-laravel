<?php

declare(strict_types=1);

namespace slash197\eTransport\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * The client for the account configured in config/etransport.php.
 *
 * @method static \slash197\eTransport\Response\Upload upload(\slash197\eTransport\Message|string $message, ?string $cif = null)
 * @method static \slash197\eTransport\Response\Status status(\slash197\eTransport\Response\Upload|string $idIncarcare)
 * @method static list<\slash197\eTransport\Response\ListedMessage> messages(string $cif, int $days = 60)
 * @method static list<\slash197\eTransport\Response\TransportInfo> info(string $cuiOp, ?string $cuiDecl = null, ?string $uit = null, ?string $refDeclarant = null)
 * @method static \slash197\eTransport\Response\Outcome outcome(\slash197\eTransport\Response\Upload|string $idIncarcare, string $cif, ?\slash197\eTransport\MessageType $type = null, ?string $uit = null, ?string $refDeclarant = null, ?\DateTimeInterface $uploadedAt = null)
 *
 * @see \slash197\eTransport\Client
 */
final class ETransport extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \slash197\eTransport\Client::class;
    }
}
