<?php

namespace Corpus\Smells;

/**
 * A value object whose constructor promotes every parameter and has no body.
 *
 * Carries `code-smell.is-vo-constructor`, which nothing else in the corpus
 * publishes: the long-parameter-list rule reads it to tell a wide constructor
 * apart from a wide method, and a key no case fires is a key no rename can be
 * proved on.
 */
final readonly class Money
{
    public function __construct(
        public int $amount,
        public string $currency,
        public string $scale,
        public bool $negative,
    ) {}
}
