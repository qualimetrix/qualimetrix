<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Pattern;

use InvalidArgumentException;
use Qualimetrix\Core\Path\RelativePath;

/**
 * A selector definition bound once to the project-relative path separator.
 */
final readonly class PathPattern
{
    private const string SEPARATOR = '/';

    private CompiledSelector $compiled;

    /**
     * @throws InvalidArgumentException when the definition cannot address paths
     */
    public function __construct(public SelectorDefinition $definition)
    {
        CompiledSelector::assertShape($definition, self::SEPARATOR, '\\', 'path');
        $this->compiled = new CompiledSelector($definition, self::SEPARATOR);
    }

    public function rendered(): string
    {
        return $this->compiled->rendered;
    }

    /**
     * @throws SelectorMatchFailure when PCRE cannot complete the match
     */
    public function matches(RelativePath $path): bool
    {
        return $this->compiled->matches($path->value());
    }

}
