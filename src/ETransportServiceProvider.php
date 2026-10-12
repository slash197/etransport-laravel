<?php

declare(strict_types=1);

namespace slash197\eTransport\Laravel;

use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Log\LogManager;
use Illuminate\Support\ServiceProvider;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;
use slash197\eTransport\Client;
use slash197\eTransport\Environment;
use slash197\eTransport\Laravel\Console\CheckCommand;
use slash197\eTransport\Laravel\Console\PollCommand;
use slash197\eTransport\PollSchedule;
use slash197\eTransport\Validation\Validator;

final class ETransportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/etransport.php', 'etransport');

        $this->app->singletonIf(Environment::class, function (Application $app): Environment {
            $environment = self::string($app->make(ConfigRepository::class), 'environment');

            return Environment::tryFrom($environment)
                ?? throw new \InvalidArgumentException("Unknown e-Transport environment \"{$environment}\"; use \"test\" or \"production\".");
        });

        $this->app->singletonIf(TokenProvider::class, ConfigTokenProvider::class);

        // Applications bind their own to check on another rhythm
        $this->app->singletonIf(PollSchedule::class, static fn (): PollSchedule => new PollSchedule());

        $this->app->singletonIf(Validator::class, static fn (): Validator => new Validator());

        $this->app->scoped(Accounts::class, function (Application $app): Accounts {
            $config = $app->make(ConfigRepository::class);
            $channel = self::string($config, 'log_channel');

            return new Accounts(
                $app->bound(ClientInterface::class)
                    ? $app->make(ClientInterface::class)
                    : new GuzzleClient([
                        'timeout' => $config->get('etransport.http.timeout'),
                        'connect_timeout' => $config->get('etransport.http.connect_timeout'),
                    ]),
                $app->make(TokenProvider::class),
                $app->make(Environment::class),
                $channel !== ''
                    ? $app->make(LogManager::class)->channel($channel)
                    : $app->make(LoggerInterface::class),
            );
        });

        $this->app->bind(Client::class, static fn (Application $app): Client => $app->make(Accounts::class)->client());
    }

    public function boot(): void
    {
        if (!$this->app->runningInConsole()) {
            return;
        }

        $this->publishes([__DIR__ . '/../config/etransport.php' => $this->app->configPath('etransport.php')], 'etransport-config');
        $this->commands([CheckCommand::class]);

        if ((bool) $this->app->make(ConfigRepository::class)->get('etransport.outbox.enabled')) {
            $this->commands([PollCommand::class]);
            $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
            $this->publishes(
                [__DIR__ . '/../database/migrations' => $this->app->databasePath('migrations')],
                'etransport-migrations',
            );
        }
    }

    private static function string(ConfigRepository $config, string $key): string
    {
        $value = $config->get("etransport.{$key}");

        return is_scalar($value) ? (string) $value : '';
    }
}
