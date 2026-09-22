<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Pattern;

use InvalidArgumentException;

/**
 * A valid authored selector exhausted PCRE while matching one concrete subject.
 */
final class SelectorMatchFailure extends InvalidArgumentException
{
    public function __construct(
        public readonly SelectorDefinition $definition,
        public readonly string $pcreDiagnostic,
    ) {
        parent::__construct(\sprintf(
            'Selector "%s" could not match: %s',
            $definition->display(),
            $pcreDiagnostic,
        ));
    }
}
