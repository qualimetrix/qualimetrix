<?php

declare(strict_types=1);

namespace QmxDirectiveAudit;

/**
 * One authored `@qmx-threshold` site as the enumeration records it.
 *
 * A separate type from {@see AuditedVerdict} and not a looser shared one: the
 * enumeration knows nothing about a verdict and the audit publishes no authored
 * values, so a single type over both inputs would have to invent the fields the
 * other side does not carry — which is exactly the defaulted `form` this
 * library removed.
 */
final readonly class EnumeratedSite
{
    public function __construct(
        public string $file,
        public int $line,
        public string $target,
        public string $values,
    ) {}

    public function site(): string
    {
        return \sprintf('%s:%d:%s', $this->file, $this->line, $this->target);
    }
}
