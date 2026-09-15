<?php

declare(strict_types=1);

/**
 * Fixture: a project written entirely in the global namespace.
 *
 * Concrete, depended upon by nothing outside the file set, so the namespace
 * sits far from the main sequence — exactly the shape that used to leave no
 * trace in the project aggregate.
 */
class GlobalNamespaceStorage
{
    /** @var array<string, string> */
    private array $items = [];

    public function put(string $key, string $value): void
    {
        $this->items[$key] = $value;
    }

    public function get(string $key): ?string
    {
        return $this->items[$key] ?? null;
    }
}
