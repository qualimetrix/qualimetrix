<?php

namespace Corpus\Security;

class Login
{
    public function authenticate(string $password, string $token, string $secret): string
    {
        return $password . $token . $secret;
    }
}
