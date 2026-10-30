<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling\Contract\Configuration;

use Qualimetrix\Core\Pattern\NamespacePattern;

/** The authored framework namespace selectors and who wrote the list. */
final readonly class FrameworkNamespaces
{
    /**
     * @param list<NamespacePattern> $patterns
     * @param string|null $writtenBy the sentence naming the sources that wrote the list, which is replaced
     *                               whole; null when the carrier was not built from a document
     */
    public function __construct(
        public array $patterns = [],
        public ?string $writtenBy = null,
    ) {}
}
