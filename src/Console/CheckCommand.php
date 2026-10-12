<?php

declare(strict_types=1);

namespace slash197\eTransport\Laravel\Console;

use Illuminate\Console\Command;
use slash197\eTransport\Environment;
use slash197\eTransport\Laravel\Accounts;

final class CheckCommand extends Command
{
    protected $signature = 'etransport:check
        {cif : The tax code to read the message list of, digits only}
        {--account=default : The account whose token to use}';

    protected $description = 'Check the e-Transport configuration with a read-only call: the message list of a tax code';

    public function handle(Accounts $accounts, Environment $environment): int
    {
        $cif = $this->argument('cif');
        $account = $this->option('account');
        $account = is_string($account) && $account !== '' ? $account : Accounts::DEFAULT;

        $this->components->twoColumnDetail('Environment', $environment->value);
        $this->components->twoColumnDetail('Account', $account);

        try {
            $messages = $accounts->client($account)->messages(is_string($cif) ? $cif : '', 1);
        } catch (\Throwable $exception) {
            $this->components->twoColumnDetail('Message list', '<fg=red>failed</>');
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $count = count($messages);
        $this->components->twoColumnDetail('Message list', "ok, {$count} " . ($count === 1 ? 'message' : 'messages') . ' in the last day');

        return self::SUCCESS;
    }
}
