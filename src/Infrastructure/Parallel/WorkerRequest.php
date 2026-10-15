<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Parallel;

final readonly class WorkerRequest
{
    public function __construct(public int $count, public ?string $source = null) {}

    public function explicit(): bool
    {
        return $this->source !== null;
    }

    public function describe(): string
    {
        return $this->source ?? 'automatic worker detection';
    }
}
