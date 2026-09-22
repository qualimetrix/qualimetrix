<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Pattern;

use InvalidArgumentException;

/**
 * A selector definition bound once to the PHP namespace separator.
 */
final readonly class NamespacePattern
{
    private const string SEPARATOR = '\\';

    private CompiledSelector $compiled;

    /**
     * @throws InvalidArgumentException when the definition cannot address namespaces
     */
    public function __construct(public SelectorDefinition $definition)
    {
        CompiledSelector::assertShape($definition, self::SEPARATOR, '/', 'namespace');
        $this->compiled = new CompiledSelector($definition, self::SEPARATOR);
    }

    public function rendered(): string
    {
        return $this->compiled->rendered;
    }

    /**
     * @throws SelectorMatchFailure when PCRE cannot complete the match
     */
    public function matches(string $namespace): bool
    {
        return $this->compiled->matches($namespace);
    }

}
