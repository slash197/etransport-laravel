#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Runs the bridge against ANAF's test environment, in a throwaway Laravel
 * application with an in-memory database. Needs ETRANSPORT_TOKEN and
 * ETRANSPORT_CIF.
 *
 *   php bin/smoke.php                 runs etransport:check; changes nothing
 *   php bin/smoke.php --upload        declares a made-up national transport through the outbox and polls for its outcome
 *   php bin/smoke.php --upload=1      the same, for a transport that starts in so many days
 *   php bin/smoke.php --delete=UIT    withdraws a notification through the outbox and polls for its outcome
 *
 * The token is never printed.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Events\Dispatcher;
use Orchestra\Testbench\Foundation\Application;
use slash197\eTransport\Data\BunuriTransportate;
use slash197\eTransport\Data\DateTransport;
use slash197\eTransport\Data\DocumentTransport;
use slash197\eTransport\Data\Judet;
use slash197\eTransport\Data\Locatie;
use slash197\eTransport\Data\LocTraseuRutier;
use slash197\eTransport\Data\Notificare;
use slash197\eTransport\Data\PartenerComercial;
use slash197\eTransport\Data\ScopOperatiune;
use slash197\eTransport\Data\TipDocument;
use slash197\eTransport\Data\TipOperatiune;
use slash197\eTransport\Laravel\ETransportServiceProvider;
use slash197\eTransport\Laravel\Events\MessageAccepted;
use slash197\eTransport\Laravel\Events\MessageGivenUp;
use slash197\eTransport\Laravel\Events\MessageRejected;
use slash197\eTransport\Laravel\Outbox\Outbox;
use slash197\eTransport\Laravel\Outbox\OutboxMessage;
use slash197\eTransport\Message;
use Symfony\Component\Console\Output\BufferedOutput;

require dirname(__DIR__) . '/vendor/autoload.php';

$cif = trim((string) getenv('ETRANSPORT_CIF'));
if (trim((string) getenv('ETRANSPORT_TOKEN')) === '' || $cif === '') {
    fwrite(STDERR, "Set ETRANSPORT_TOKEN and ETRANSPORT_CIF in .env (see .env.example).\n");
    exit(2);
}

$app = Application::create(options: ['extra' => ['providers' => [ETransportServiceProvider::class]]]);
$config = $app->make('config');
// Always the test environment, whatever .env says
$config->set('etransport.environment', 'test');
$config->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
$config->set('database.default', 'testing');

$artisan = static function (string $command, array $parameters = []) use ($app): int {
    $output = new BufferedOutput();
    $code = $app->make(Kernel::class)->call($command, $parameters, $output);
    echo trim($output->fetch()), "\n";

    return $code;
};
$json = static fn (mixed $value): string => (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$show = static fn (OutboxMessage $message): string => $json(array_diff_key($message->refresh()->attributesToArray(), ['payload' => 1]));
$options = getopt('', ['upload::', 'delete:']);

$today = new DateTimeImmutable('now', new DateTimeZone('Europe/Bucharest'));
$message = match (true) {
    isset($options['upload']) => Message::notificare($cif, new Notificare(
        TipOperatiune::TransportNational,
        [new BunuriTransportate(ScopOperatiune::Comercializare, 'Mobilier din lemn (test)', 12.5, 'H87', 510.25, codTarifar: '94036010', greutateNeta: 480, valoareLeiFaraTva: 18250)],
        new PartenerComercial('RO', 'Partener Exemplu SRL', $cif),
        new DateTransport('B100ABC', 'RO', 'Transportator Exemplu SRL', $today->modify('+' . (int) $options['upload'] . ' days'), codOrgTransport: $cif),
        LocTraseuRutier::locatie(new Locatie(Judet::Cluj, 'Cluj-Napoca', 'Strada Exemplu', '1')),
        LocTraseuRutier::locatie(new Locatie(Judet::Bucuresti, 'Bucuresti', 'Bulevardul Exemplu', '2')),
        [new DocumentTransport(TipDocument::AvizDeInsotire, $today, 'TEST-1')],
    ), 'SMOKE-' . $today->format('YmdHis')),
    is_string($options['delete'] ?? null) => Message::stergere($cif, $options['delete']),
    default => null,
};

if ($message === null) {
    exit($artisan('etransport:check', ['cif' => $cif]));
}

$artisan('migrate');

$events = $app->make(Dispatcher::class);
foreach ([MessageAccepted::class, MessageRejected::class, MessageGivenUp::class] as $event) {
    $events->listen($event, static function (object $event): void {
        echo 'Event: ', (new ReflectionClass($event))->getShortName(), "\n";
    });
}

try {
    $stored = $app->make(Outbox::class)->upload($message);
} catch (Throwable $exception) {
    echo 'FAILED: ', $exception->getMessage(), "\n";
    echo 'Stored: ', $show(OutboxMessage::query()->sole()), "\n";
    exit(1);
}
echo 'Uploaded: ', $show($stored), "\n";

// Only the first two minutes or so, polling as a scheduler would every 20 seconds.
$until = time() + 150;
while ($stored->next_check_at !== null && time() < $until) {
    sleep(max(1, min(20, $stored->next_check_at->getTimestamp() - time())));
    $artisan('etransport:poll');
    $stored->refresh();
}

echo 'Stored: ', $show($stored), "\n";
