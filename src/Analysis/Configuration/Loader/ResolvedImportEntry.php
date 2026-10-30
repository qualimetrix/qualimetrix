<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Loader;

use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;

/** One entry of an `imports` list and the files it leads to. */
final readonly class ResolvedImportEntry
{
    public function __construct(
        /** N of `imports[N]`. */
        public int $position,
        /** To refuse at the entry. */
        public ResolvedValueInterface $value,
        public bool $isSelector,
        /**
         * Byte order of the path for a selector, which may match nothing; exactly one for any other entry.
         *
         * @var list<ImportTarget>
         */
        public array $targets,
    ) {}
}
