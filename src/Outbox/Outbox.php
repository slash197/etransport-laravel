<?php

declare(strict_types=1);

namespace slash197\eTransport\Laravel\Outbox;

use Illuminate\Database\Eloquent\Model;
use slash197\eTransport\Exception\ApiException;
use slash197\eTransport\Laravel\Accounts;
use slash197\eTransport\Laravel\Exception\ValidationException;
use slash197\eTransport\Message;
use slash197\eTransport\PollSchedule;
use slash197\eTransport\Validation\Validator;

/**
 * Uploads messages and stores them, so that etransport:poll can find out
 * what became of each.
 */
final class Outbox
{
    public function __construct(
        private readonly Accounts $accounts,
        private readonly Validator $validator,
        private readonly PollSchedule $schedule,
    ) {
    }

    /**
     * Validates the message, uploads it now and stores it for the outcome
     * to be checked. Nothing is uploaded or stored when validation fails.
     *
     * A failed upload is never tried again by this package: ANAF may have
     * received a message whose answer was lost, and a second upload of a
     * notification would be a second notification. The stored message is
     * marked as failed and the exception is thrown on.
     *
     * @param Message|string       $message  A Message, or an XML document built elsewhere
     * @param string               $account  Whose token to upload and check with, see TokenProvider
     * @param string|null          $cif      The tax code to upload for; the declarant of the message by default
     * @param Model|null           $subject  The application's record the message is about
     * @param array<string, mixed> $meta     Anything the application wants back when the outcome arrives
     * @param bool                 $validate False uploads without checking the message locally first
     *
     * @throws ValidationException
     * @throws ApiException              When ANAF did not take the message
     * @throws \InvalidArgumentException When an XML document is not an e-Transport message
     */
    public function upload(
        Message|string $message,
        string $account = Accounts::DEFAULT,
        ?string $cif = null,
        ?Model $subject = null,
        array $meta = [],
        bool $validate = true,
    ): OutboxMessage {
        if ($validate) {
            $violations = $this->validator->validate($message);
            if (!$violations->isEmpty()) {
                throw new ValidationException($violations);
            }
        }

        $document = Document::from($message);
        $cif ??= $document->codDeclarant;

        // Stored before the upload, so that a message ANAF took is on record whatever happens next
        $stored = OutboxMessage::query()->create([
            'account' => $account,
            'cif' => $cif,
            'type' => $document->type,
            'uit' => $document->uit,
            'reference' => $document->refDeclarant,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject === null ? null : (string) $subject->getKey(),
            'status' => MessageStatus::Uploading,
            'payload' => $document->xml,
            'meta' => $meta === [] ? null : $meta,
        ]);

        try {
            $upload = $this->accounts->client($account)->upload($document->xml, $cif);
        } catch (\Throwable $exception) {
            $stored->update([
                'status' => MessageStatus::Failed,
                'errors' => [$exception->getMessage()],
                'resolved_at' => now(),
            ]);

            throw $exception;
        }

        $uploadedAt = now();
        $stored->update([
            'status' => MessageStatus::Processing,
            'upload_id' => $upload->idIncarcare,
            'uit' => $upload->uit ?? $document->uit,
            'uploaded_at' => $uploadedAt,
            'next_check_at' => $this->schedule->nextCheck($uploadedAt, 0),
        ]);

        return $stored;
    }
}
