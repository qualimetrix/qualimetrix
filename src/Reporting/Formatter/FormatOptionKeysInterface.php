<?php

declare(strict_types=1);

namespace Qualimetrix\Reporting\Formatter;

/**
 * Opt-in declaration of the `--format-opt` keys a formatter understands.
 *
 * Implemented only by formatters that read at least one key; the union over the
 * registry is what {@see FormatterRegistryInterface::declaredFormatOptionKeys()}
 * returns, and a key outside that union is refused instead of being carried into
 * an options array nobody reads.
 *
 * A key is declared by the formatter even when the reading happens in one of its
 * renderers: the renderer is a collaborator of exactly one formatter, and the
 * user names the formatter, not the renderer. The divergence this creates -- a
 * declaration drifting away from the reader -- is what the enumerating test
 * (`tests/Unit/Reporting/Formatter/FormatOptionKeyDeclarationTest.php`) checks.
 */
interface FormatOptionKeysInterface
{
    /**
     * `--format-opt` keys this formatter and its renderers read.
     *
     * @return list<string>
     */
    public function formatOptionKeys(): array;
}
