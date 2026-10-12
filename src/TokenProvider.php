<?php

declare(strict_types=1);

namespace slash197\eTransport\Laravel;

/**
 * Tells the bridge which ANAF OAuth access token to use for an account.
 * Applications that keep a token per company or per user implement this
 * and bind it in the container; the account key is whatever leads them to
 * a token, such as a company id or a user and company pair.
 *
 * It is called before every request, so return a token that is valid now:
 * refresh it here if it has expired. A message is checked for up to a day
 * after its upload, with the account it was uploaded with.
 */
interface TokenProvider
{
    /**
     * @throws \InvalidArgumentException When the account is unknown or has no token
     */
    public function token(string $account): string;
}
