<?php

declare(strict_types=1);

namespace slash197\eTransport\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use slash197\eTransport\Laravel\Outbox\OutboxPoller;

final class PollCommand extends Command
{
    protected $signature = 'etransport:poll
        {--limit= : Most messages to check in this run; defaults to etransport.outbox.limit}';

    protected $description = 'Ask ANAF for the outcome of the uploaded e-Transport messages whose check is due';

    public function handle(OutboxPoller $poller, Repository $config): int
    {
        $limit = $this->option('limit') ?? $config->get('etransport.outbox.limit');

        $resolved = $poller->pollDue(is_numeric($limit) && (int) $limit > 0 ? (int) $limit : null);

        $this->components->info("Got the outcome of {$resolved} " . ($resolved === 1 ? 'message' : 'messages') . '.');

        return self::SUCCESS;
    }
}
