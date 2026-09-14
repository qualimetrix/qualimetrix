<?php

declare(strict_types=1);

class GlobalNamespaceService
{
    public function __construct(private readonly GlobalNamespaceStorage $storage) {}

    public function remember(string $key, string $value): string
    {
        $this->storage->put($key, $value);

        return $this->storage->get($key) ?? $value;
    }
}
