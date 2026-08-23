<?php

namespace Corpus\Coupling\Contract;

interface MailerInterface
{
    public function handle(string $payload): string;
}
