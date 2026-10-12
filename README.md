# etransport-laravel

[![CI](https://github.com/slash197/etransport-laravel/actions/workflows/ci.yml/badge.svg)](https://github.com/slash197/etransport-laravel/actions/workflows/ci.yml)
[![Latest version](https://img.shields.io/packagist/v/slash197/etransport-laravel)](https://packagist.org/packages/slash197/etransport-laravel)

Laravel bridge for [slash197/etransport](https://github.com/slash197/etransport), the PHP client for ANAF's RO e-Transport system.

> **Status: 0.x.** Verified against ANAF's test environment, but the API of this package may still change before 1.0. See the [changelog](CHANGELOG.md).

Declaring a transport is not a single HTTP call. An upload only confirms that ANAF received the message; whether it was accepted, and for a notification its UIT's final state, is known a minute or more later and has to be asked for, within ANAF's daily limits. This package takes care of that part for a Laravel application:

- **The client in the container**, configured from `config/etransport.php`, for one token or for a token per company or user.
- **An outbox.** Messages are validated, uploaded and stored, and their outcome is asked for on a schedule that stays inside ANAF's limits.
- **Events** for accepted, rejected and unanswered messages, carrying your own model and context.
- **Artisan commands** to poll for outcomes and to check the setup.

What stays in your application is what only it knows: turning your shipments into messages, obtaining and refreshing ANAF's OAuth tokens, and what to do when a message is accepted or refused. Building messages, local validation and how ANAF behaves are described in the [core package's README](https://github.com/slash197/etransport#readme); read it alongside this one.

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Quick start](#quick-start)
- [Configuration](#configuration)
- [Tokens and accounts](#tokens-and-accounts)
- [Uploading messages](#uploading-messages)
- [Polling for the outcome](#polling-for-the-outcome)
- [The stored message](#the-stored-message)
- [Commands](#commands)
- [Events](#events)
- [Without the outbox](#without-the-outbox)
- [HTTP client and logging](#http-client-and-logging)
- [Commercial data](#commercial-data)
- [Troubleshooting](#troubleshooting)
- [Contributing](#contributing)
- [Development](#development)

## Requirements

- PHP 8.2 or newer
- Laravel 12 or 13
- A database, for the outbox table (not needed if you [switch the outbox off](#without-the-outbox))
- The scheduler running (`php artisan schedule:work`, or cron calling `schedule:run`)
- An ANAF OAuth access token with the right to declare for the tax code. This package does not run the OAuth flow; [andalisolutions/oauth2-anaf](https://github.com/andalisolutions/oauth2-anaf) does

Laravel's queue is not used.

## Installation

```bash
composer require slash197/etransport-laravel
php artisan migrate
```

The service provider and the `ETransport` facade are discovered automatically. The migration creates one table, `etransport_messages`.

Publish the config file if you want to change more than the environment variables allow, and the migration if you want to alter it:

```bash
php artisan vendor:publish --tag=etransport-config
php artisan vendor:publish --tag=etransport-migrations
```

## Quick start

For one token, on ANAF's test environment.

```dotenv
ETRANSPORT_ENVIRONMENT=test
ETRANSPORT_TOKEN=your-anaf-access-token
```

```bash
php artisan etransport:check 10000001
```

Schedule the poll in `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('etransport:poll')->everyTwentySeconds()->withoutOverlapping();
```

Upload a message and react to its outcome:

```php
use slash197\eTransport\Laravel\Outbox\Outbox;
use slash197\eTransport\Message;

$stored = app(Outbox::class)->upload(
    Message::stergere('10000001', '3V0P0L0P0T3JUW46'),
    subject: $shipment,
);
```

```php
use Illuminate\Support\Facades\Event;
use slash197\eTransport\Laravel\Events\MessageAccepted;
use slash197\eTransport\Laravel\Events\MessageRejected;

Event::listen(function (MessageAccepted $event): void {
    $event->message->subject?->update(['uit' => $event->message->uit]);
});

Event::listen(function (MessageRejected $event): void {
    logger()->warning('e-Transport refused a message', ['errors' => $event->message->errors]);
});
```

## Configuration

| Variable | Default | Meaning |
|---|---|---|
| `ETRANSPORT_ENVIRONMENT` | `test` | `test` or `production` |
| `ETRANSPORT_TOKEN` | | The access token of the `default` account; leave empty when you bind a `TokenProvider` |
| `ETRANSPORT_OUTBOX` | `true` | `false` switches the outbox off: no table, no `etransport:poll` |
| `ETRANSPORT_LOG_CHANNEL` | the default channel | Where request metadata is logged |

The published config file also holds the table name (`outbox.table`), how many messages one poll run checks (`outbox.limit`, 100) and the timeouts of the built-in HTTP client.

The same ANAF token works on both environments; the environment only chooses the URL.

## Tokens and accounts

Every part of the bridge addresses an **account** by a key: a string that leads your application to an access token. With nothing else configured there is one account, `default`, whose token is `ETRANSPORT_TOKEN`.

Applications that keep a token per company or per user implement `TokenProvider` and bind it:

```php
use slash197\eTransport\Laravel\TokenProvider;

final class AnafTokens implements TokenProvider
{
    public function token(string $account): string
    {
        $token = AnafToken::query()->where('company_id', $account)->first()
            ?? throw new \InvalidArgumentException("Company {$account} has no ANAF token.");

        return $token->refreshIfExpired()->access_token;
    }
}
```

```php
$this->app->singleton(TokenProvider::class, AnafTokens::class);
```

The provider is called before every request, so it must return a token that is valid now. The account a message was uploaded with is stored with it and used again for every check, up to a day later: choose a key that still leads to a token then. Because ANAF counts its limits per user, an account key that names the user as well as the company (`"7:42"`) keeps one user's uploads from using up another's.

`app(Accounts::class)->client('7')` gives the core client of an account, for calls outside the outbox.

## Uploading messages

```php
$stored = $outbox->upload(
    $message,             // a Message, or an XML document you built yourself
    account: '7',         // defaults to "default"
    cif: null,            // defaults to the declarant of the message
    subject: $shipment,   // any Eloquent model
    meta: ['user' => 5],  // anything you want back with the outcome
);
```

`upload()` does three things, in order:

1. **Validates** the message against ANAF's schema and rules, locally. An invalid message throws `Exception\ValidationException` (its `violations` say what is wrong); nothing is uploaded or stored. Pass `validate: false` to leave validation to ANAF.
2. **Stores** the message with status `uploading`.
3. **Uploads** it. On success the stored message becomes `processing`, with ANAF's upload id and the time of its first check.

If the upload fails, the stored message becomes `failed`, `errors` holds the reason and the core's `ApiException` is thrown on to you. **A failed upload is never retried by this package.** When a request times out, ANAF may have received the message all the same, and uploading a notification twice declares the transport twice. Whether to try again is your decision; `ApiException::isRetryable()` and ANAF's message list (`ETransport::messages($cif)`) help to make it.

## Polling for the outcome

`etransport:poll` asks ANAF about every `processing` message whose check is due and records what it learns. The rhythm is the core's `PollSchedule`: 20, 70 and 130 seconds after the upload, then after about 4, 9, 19 and 34 minutes, then every 30 minutes until a day has passed. That is 53 checks at most, inside ANAF's limit of 100 status calls a day per upload.

- Run it every 20 seconds, as in the quick start, to get the first answers as early as ANAF has them. Every minute works too; the first checks then land up to a minute later.
- Checks whose time passed while nothing was polling are skipped, not made up for.
- A check that fails (ANAF down, a token that cannot be had) is recorded in `errors` and the next check is made as scheduled. Failures other than ANAF's own are also reported to your exception handler.
- When the schedule ends without an answer, the message becomes `given_up`. Whether it went through is then unknown.
- Each check of a message ANAF has processed also reads ANAF's message list, which answers 1000 times a day per user. `outbox.limit` caps the checks of one run.

To check on another rhythm, bind your own schedule:

```php
$this->app->singleton(\slash197\eTransport\PollSchedule::class, fn () => new PollSchedule([30, 60, 120], 3600, 86400));
```

## The stored message

`slash197\eTransport\Laravel\Outbox\OutboxMessage` is an Eloquent model.

| Attribute | Meaning |
|---|---|
| `account` | The key the `TokenProvider` is asked with |
| `cif` | The tax code the message was uploaded for |
| `type` | `MessageType`: notification, correction, deletion, confirmation or vehicle change |
| `uit` | The UIT the message was about, or the one ANAF assigned |
| `upload_id` | ANAF's id for the upload (`index_incarcare`) |
| `reference` | The declarant's own reference (`refDeclarant`) |
| `subject` | Your model, if you gave one |
| `meta` | Your array, if you gave one |
| `status` | See below |
| `payload` | The XML as uploaded |
| `errors` | Why ANAF refused the message, or why the upload or the last check failed |
| `checks`, `next_check_at`, `last_check_at` | Where the message is on the poll schedule |
| `uploaded_at`, `resolved_at` | When ANAF received it, and when its status became final |

| Status | Meaning |
|---|---|
| `uploading` | Being uploaded. A row left here by a process that died may or may not have reached ANAF |
| `processing` | Received by ANAF; outcome not known yet |
| `accepted` | ANAF processed the message and found it valid |
| `rejected` | ANAF processed the message and refused it |
| `failed` | The upload itself failed |
| `given_up` | No outcome before the poll schedule ended |

The package never deletes rows. Prune old ones yourself if you do not need them.

## Commands

| Command | What it does |
|---|---|
| `etransport:check {cif}` | Read-only check of the token and the environment: reads the last day of the tax code's message list. `--account=KEY` checks another account |
| `etransport:poll` | Asks for the outcome of the stored messages whose check is due. `--limit=N` caps the checks in one run |

`etransport:poll` is registered only while the outbox is enabled.

## Events

All in `slash197\eTransport\Laravel\Events`.

| Event | Properties | When |
|---|---|---|
| `MessageAccepted` | `message`, `outcome` | ANAF found the message valid. `$message->uit` is the UIT of the transport |
| `MessageRejected` | `message`, `outcome` | ANAF refused the message. `$message->errors` says why |
| `MessageGivenUp` | `message` | The poll schedule ended without an outcome |

`outcome` is the core's `Response\Outcome`, with ANAF's status answer and, when there is one, the record of its message list.

## Without the outbox

If your application already stores its uploads, set `ETRANSPORT_OUTBOX=false`. No table is created and `etransport:poll` is not registered. You still get the configured client, the accounts and `etransport:check`:

```php
use slash197\eTransport\Laravel\Facades\ETransport;

$upload = ETransport::upload($message);
$outcome = ETransport::outcome($upload, $cif, $message->type);
```

## HTTP client and logging

**HTTP client.** Requests go through a Guzzle client with the timeouts from `etransport.http`. If your application binds `Psr\Http\Client\ClientInterface` in the container, that client is used instead, and timeouts, proxies and retries are yours to configure on it. Do not let it retry uploads.

**Logging.** The client logs one line per request to `ETRANSPORT_LOG_CHANNEL`: method, endpoint, status and ANAF's trace id. Message contents are never logged.

## Commercial data

Declarations carry partners, goods, values and vehicle numbers, and ANAF's answers can quote them. The package therefore:

- encrypts `payload` and `errors` in the outbox table with the application key;
- never logs message contents.

Rotating `APP_KEY` makes stored messages unreadable unless you keep the old key in `APP_PREVIOUS_KEYS`. `meta` is stored as plain JSON. The exception messages the commands print and report can quote what ANAF answered.

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| `No e-Transport token is configured` | `ETRANSPORT_TOKEN` is empty and no `TokenProvider` is bound |
| `Unknown e-Transport account "…"` | An account key was used but no `TokenProvider` is bound, so only `default` exists |
| `etransport:check` fails with HTTP 401 or 403 | The token expired, or its owner has no right to declare for that tax code in ANAF's SPV |
| Messages stay `processing` | The scheduler is not running; or the checks keep failing, in which case `errors` says why |
| Messages end as `given_up` | The token could not be had for a day, or ANAF never answered. Look the message up in `ETransport::messages($cif)` |
| A message is `failed` | The upload did not go through; `errors` holds the reason. It is not retried, see [Uploading messages](#uploading-messages) |
| A message stays `uploading` | The process died during the upload. ANAF may have the message; check its message list before uploading again |
| The migration fails because `etransport_messages` exists | Your application has a table of that name; set `etransport.outbox.table` before migrating |

## Contributing

Issues and pull requests are welcome, including small ones. Please leave real tax codes, UITs, company names and tokens out of issues, tests and commits.

Before opening a pull request, run `make check`. Tests must not reach the network; the test base class installs a fake HTTP client for that reason.

Questions about messages, validation and how ANAF itself behaves belong in the [core package](https://github.com/slash197/etransport).

## Development

Nothing needs to be installed on the host besides Docker:

```bash
make build     # once
make install
make check     # composer validate, PHPStan level 8 and PHPUnit
```

`PHP_VERSION=8.4 make build` switches the PHP version of the container.

## Disclaimer

This is an independent, unofficial project. It is not affiliated with or endorsed by ANAF.

## Licence

MIT. See [LICENSE](LICENSE).
