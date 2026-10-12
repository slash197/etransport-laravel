<?php

declare(strict_types=1);

namespace slash197\eTransport\Laravel\Outbox;

use slash197\eTransport\Message;
use slash197\eTransport\MessageType;

/**
 * What the outbox needs to know about a message, read from a Message or
 * from an XML document the application built itself.
 *
 * @internal
 */
final readonly class Document
{
    private function __construct(
        public string $xml,
        public MessageType $type,
        public string $codDeclarant,
        public ?string $uit,
        public ?string $refDeclarant,
    ) {
    }

    /**
     * @throws \InvalidArgumentException When an XML document is not an e-Transport message
     */
    public static function from(Message|string $message): self
    {
        if ($message instanceof Message) {
            return new self($message->toXml(), $message->type, $message->codDeclarant, $message->uit, $message->refDeclarant);
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $document = new \DOMDocument();
            $root = trim($message) !== '' && $document->loadXML($message, LIBXML_NONET) ? $document->documentElement : null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $body = $root?->firstElementChild;
        if ($root === null || $root->localName !== 'eTransport' || $body === null || $root->getAttribute('codDeclarant') === '') {
            throw new \InvalidArgumentException('The XML document is not an e-Transport message.');
        }

        $correction = null;
        foreach ($body->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === 'corectie') {
                $correction = $child;
            }
        }

        $type = match ($body->localName) {
            'notificare' => $correction === null ? MessageType::Notificare : MessageType::Corectie,
            'stergere' => MessageType::Stergere,
            'confirmare' => MessageType::Confirmare,
            'modifVehicul' => MessageType::ModificareVehicul,
            default => throw new \InvalidArgumentException("Unknown e-Transport message \"{$body->localName}\"."),
        };

        return new self(
            $message,
            $type,
            $root->getAttribute('codDeclarant'),
            ($correction ?? $body)->getAttribute('uit') ?: null,
            $root->getAttribute('refDeclarant') ?: null,
        );
    }
}
