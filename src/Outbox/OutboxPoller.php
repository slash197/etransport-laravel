<?php

declare(strict_types=1);

namespace slash197\eTransport\Laravel\Outbox;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use slash197\eTransport\Exception\ApiException;
use slash197\eTransport\Laravel\Accounts;
use slash197\eTransport\Laravel\Events\MessageAccepted;
use slash197\eTransport\Laravel\Events\MessageGivenUp;
use slash197\eTransport\Laravel\Events\MessageRejected;
use slash197\eTransport\PollSchedule;
use slash197\eTransport\Response\Outcome;
use slash197\eTransport\Response\OutcomeState;

/**
 * Asks ANAF what became of the stored messages whose check is due.
 */
final class OutboxPoller
{
    /** Seconds after which a last check that never reported back counts as lost. */
    private const LOST_CHECK_SECONDS = 600;

    public function __construct(
        private readonly Accounts $accounts,
        private readonly PollSchedule $schedule,
        private readonly Dispatcher $events,
        private readonly ExceptionHandler $exceptions,
    ) {
    }

    /**
     * @param int|null $limit Most messages to check in this call; null checks every due message
     *
     * @return int Number of messages whose outcome became final
     */
    public function pollDue(?int $limit = null): int
    {
        $this->giveUpLostChecks();

        $resolved = 0;
        $checked = 0;

        $due = OutboxMessage::query()
            ->where('status', MessageStatus::Processing)
            ->where('next_check_at', '<=', now());

        foreach ($due->lazyById(100) as $message) {
            if ($limit !== null && $checked >= $limit) {
                break;
            }

            if (!$this->claim($message)) {
                continue;
            }

            $checked++;
            $resolved += $this->check($message) ? 1 : 0;
        }

        return $resolved;
    }

    /**
     * Takes the check for this process and moves the message along the
     * schedule; false when another process got there first. Checks whose
     * time passed while nothing was polling are skipped, not made up for.
     */
    private function claim(OutboxMessage $message): bool
    {
        $now = now();
        $uploadedAt = $message->uploaded_at ?? $message->created_at ?? $now;

        $checks = $message->checks + 1;
        $next = $this->schedule->nextCheck($uploadedAt, $checks);
        while ($next !== null && $next <= $now) {
            $next = $this->schedule->nextCheck($uploadedAt, ++$checks);
        }

        $claimed = OutboxMessage::query()
            ->whereKey($message->id)
            ->where('status', MessageStatus::Processing)
            ->where('checks', $message->checks)
            ->update(['checks' => $checks, 'last_check_at' => $now, 'next_check_at' => $next]) === 1;

        if ($claimed) {
            $message->refresh();
        }

        return $claimed;
    }

    /**
     * @return bool Whether the outcome is final
     */
    private function check(OutboxMessage $message): bool
    {
        try {
            $outcome = $this->accounts->client($message->account)->outcome(
                (string) $message->upload_id,
                $message->cif,
                $message->type,
                $message->uit,
                $message->reference,
                $message->uploaded_at,
            );
        } catch (\Throwable $exception) {
            // A token that cannot be had or an unknown account is the application's to hear about
            if (!$exception instanceof ApiException) {
                $this->exceptions->report($exception);
            }

            $message->update(['errors' => [$exception->getMessage()]]);
            $this->giveUpIfOver($message);

            return false;
        }

        if (!$outcome->isFinal()) {
            $message->update(['uit' => $outcome->uit ?? $message->uit, 'errors' => null]);
            $this->giveUpIfOver($message);

            return false;
        }

        $this->resolve($message, $outcome);

        return true;
    }

    private function resolve(OutboxMessage $message, Outcome $outcome): void
    {
        $accepted = $outcome->state === OutcomeState::Accepted;

        $message->update([
            'status' => $accepted ? MessageStatus::Accepted : MessageStatus::Rejected,
            'uit' => $outcome->uit ?? $message->uit,
            'errors' => $outcome->errors === [] ? null : $outcome->errors,
            'next_check_at' => null,
            'resolved_at' => now(),
        ]);

        $this->events->dispatch($accepted ? new MessageAccepted($message, $outcome) : new MessageRejected($message, $outcome));
    }

    private function giveUpIfOver(OutboxMessage $message): void
    {
        if ($message->next_check_at === null) {
            $this->giveUp($message);
        }
    }

    /**
     * Messages whose last check was claimed by a process that died before recording anything.
     */
    private function giveUpLostChecks(): void
    {
        $lost = OutboxMessage::query()
            ->where('status', MessageStatus::Processing)
            ->where('next_check_at', null)
            ->where('last_check_at', '<=', now()->subSeconds(self::LOST_CHECK_SECONDS));

        foreach ($lost->lazyById(100) as $message) {
            $this->giveUp($message);
        }
    }

    private function giveUp(OutboxMessage $message): void
    {
        $gaveUp = OutboxMessage::query()
            ->whereKey($message->id)
            ->where('status', MessageStatus::Processing)
            ->update(['status' => MessageStatus::GivenUp, 'resolved_at' => now()]) === 1;

        if ($gaveUp) {
            $this->events->dispatch(new MessageGivenUp($message->refresh()));
        }
    }
}
