<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling\Contract\Configuration;

use Qualimetrix\Analysis\Finding\Contract\ProjectScope\ConfiguredValueWriters;
use Qualimetrix\Core\Pattern\NamespacePattern;

/** The authored framework namespace selectors and the sources that wrote the list. */
final readonly class FrameworkNamespaces
{
    /**
     * @param list<NamespacePattern> $patterns
     * @param ConfiguredValueWriters|null $writers null when the carrier was not built from a document
     */
    public function __construct(
        public array $patterns = [],
        public ?ConfiguredValueWriters $writers = null,
    ) {}
}
