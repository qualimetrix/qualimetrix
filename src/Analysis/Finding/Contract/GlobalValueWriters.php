<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract;

use Qualimetrix\Analysis\Configuration\Contract\Document\ConfiguredValueWriters;
use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Core\Pattern\PathPattern;

/**
 * Who wrote each global `suppress_paths` and `suppress_namespaces` value.
 *
 * A value this does not know is judged and worded without a writer: the
 * sources are evidence about a value, never a precondition for judging it.
 */
final readonly class GlobalValueWriters
{
    /**
     * @param array<string, ConfiguredValueWriters> $paths keyed by the pattern's display
     * @param array<string, ConfiguredValueWriters> $namespaces keyed by the pattern's display
     */
    public function __construct(private array $paths = [], private array $namespaces = []) {}

    /**
     * Values given on the command line, each written by the option it came from.
     *
     * @param list<PathPattern> $paths
     * @param list<NamespacePattern> $namespaces
     */
    public static function commandLine(array $paths, string $pathOption, array $namespaces, string $namespaceOption): self
    {
        return new self(
            array_fill_keys(self::displays($paths), ConfiguredValueWriters::commandLine($pathOption)),
            array_fill_keys(self::displays($namespaces), ConfiguredValueWriters::commandLine($namespaceOption)),
        );
    }

    public function for(PathPattern|NamespacePattern $pattern): ?ConfiguredValueWriters
    {
        return ($pattern instanceof PathPattern ? $this->paths : $this->namespaces)[$pattern->definition->display()] ?? null;
    }

    /** An equal value written on both sides is one value with both writers, this side's first. */
    public function with(self $other): self
    {
        return new self(self::united($this->paths, $other->paths), self::united($this->namespaces, $other->namespaces));
    }

    /**
     * @param array<string, ConfiguredValueWriters> $own
     * @param array<string, ConfiguredValueWriters> $other
     *
     * @return array<string, ConfiguredValueWriters>
     */
    private static function united(array $own, array $other): array
    {
        foreach ($other as $display => $writers) {
            $own[$display] = isset($own[$display]) ? $own[$display]->with($writers) : $writers;
        }

        return $own;
    }

    /**
     * @param list<PathPattern|NamespacePattern> $patterns
     *
     * @return list<string>
     */
    private static function displays(array $patterns): array
    {
        return array_map(static fn(PathPattern|NamespacePattern $pattern): string => $pattern->definition->display(), $patterns);
    }
}
