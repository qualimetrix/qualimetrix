<?php

namespace Corpus\Security;

class Credentials
{
    private string $password = 'hunter2secretpassword';
    // Not a Stripe-shaped literal on purpose: GitHub push protection rejects a
    // push whose diff merely LOOKS like a live key, fake or not.
    private const API_KEY = 'corpus-fixture-api-key-not-a-real-secret';

    public function fingerprint(): string
    {
        return $this->password . self::API_KEY;
    }
}
