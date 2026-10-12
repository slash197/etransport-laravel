# Changelog

All notable changes to this package are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the package adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html). While the version is 0.x, a minor release may contain breaking changes.

## [0.1.0] - 2026-10-12

First release.

### Added

- Service provider with a publishable `config/etransport.php`, binding `slash197\eTransport\Client` in the container for the `test` or `production` environment, and an `ETransport` facade for it.
- `TokenProvider` and `Accounts`, for applications that keep an ANAF token per company or per user: every part of the bridge addresses an account by a key the application chooses, and the token is asked for at every request. Without a provider of your own, the token in `ETRANSPORT_TOKEN` is the `default` account.
- An outbox: `Outbox::upload()` validates a message, uploads it and stores it in `etransport_messages`, and `etransport:poll` asks ANAF for its outcome on the core's `PollSchedule`. `MessageAccepted`, `MessageRejected` and `MessageGivenUp` announce the outcome. It can be switched off with `ETRANSPORT_OUTBOX=false`.
- `etransport:check`, which verifies the token and the environment with a read-only call.

[0.1.0]: https://github.com/slash197/etransport-laravel/releases/tag/v0.1.0
