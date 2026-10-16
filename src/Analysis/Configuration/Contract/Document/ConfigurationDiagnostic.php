<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Contract\Document;

/**
 * A warning about accepted configuration — legal, and still worth telling the
 * author — with the layers it is about. It travels with the resolved document
 * to every command that reads it.
 */
final readonly class ConfigurationDiagnostic
{
    /** @param non-empty-list<Provenance> $sources lowest precedence first */
    public function __construct(
        public string $message,
        public array $sources,
    ) {}
}
