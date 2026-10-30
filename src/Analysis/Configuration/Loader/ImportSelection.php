<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Loader;

use Closure;
use InvalidArgumentException;
use Qualimetrix\Core\FileTarget\FileIdentity;
use Qualimetrix\Core\FileTarget\NativeCall;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Pattern\PathPattern;
use Qualimetrix\Core\Pattern\SelectorKind;

/**
 * The files an `imports` selector (`subtree` or `regex`) stands for: the
 * configuration files under the importing file's directory whose path, relative
 * to that directory, the selector matches in full.
 *
 * The walk is our own: it refuses a directory it cannot list or enter, where
 * an ignored one would silently shorten the list and change what is enforced.
 * The literal directory a regex starts with bounds the walk and must exist;
 * every path offered to the selector is built from names read from listings,
 * so matching is the whole-path match of {@see PathPattern} on any filesystem,
 * whatever its case or Unicode folding. A directory is walked under every
 * name that leads to it; only a link back to one of its own ancestors ends.
 */
final class ImportSelection
{
    /** Characters that end the literal run a regex fragment starts with. */
    private const string REGEX_METACHARACTERS = '\\^$.|?*+()[]{}';

    /** A quantifier makes the character before it optional or repeated, so it is not a literal. */
    private const string QUANTIFIERS = '?*+{';

    /** @var list<array{string, string}> */
    private array $found = [];

    /** @param Closure(string): never $refuse */
    private function __construct(
        private readonly PathPattern $selector,
        private readonly string $displayBase,
        private readonly Closure $refuse,
    ) {}

    /** Why the selector cannot name files below the importing directory, or null. */
    public static function problem(PathPattern $selector): ?string
    {
        if ($selector->definition->kind !== SelectorKind::Subtree) {
            return null;
        }

        return array_intersect(explode('/', $selector->definition->value), ['.', '..']) === []
            ? null
            : 'names a directory with a "." or ".." segment; a subtree is named below the importing file\'s directory';
    }

    /**
     * The directory the walk starts in, relative to the importing file's
     * directory and without a trailing slash; empty for that directory itself.
     */
    public static function walkRoot(PathPattern $selector): string
    {
        $definition = $selector->definition;

        return $definition->kind === SelectorKind::Subtree ? $definition->value : self::literalDirectory($definition->value);
    }

    /**
     * The files the selector matches, as [physical path, display path] in byte
     * order of the display path. Zero matches are a valid answer.
     *
     * @param Closure(string): never $refuse completes "Import ... " with the problem
     *
     * @return list<array{string, string}>
     */
    public static function matches(PathPattern $selector, string $physicalBase, string $displayBase, Closure $refuse): array
    {
        $walk = new self($selector, $displayBase, $refuse);
        [$physical, $relative] = $walk->enterRoot($physicalBase);
        $walk->descend($physical, $relative, []);

        usort($walk->found, static fn(array $a, array $b): int => strcmp($a[1], $b[1]));

        return $walk->found;
    }

    /**
     * The leading run of literal characters, less the last one when a
     * quantifier follows it, up to its last slash. An alternation makes every
     * part optional, and a ")" may close the group the pattern is wrapped in,
     * so a fragment holding either has no bound.
     */
    private static function literalDirectory(string $fragment): string
    {
        if (strpbrk($fragment, '|)') !== false) {
            return '';
        }

        $length = strcspn($fragment, self::REGEX_METACHARACTERS);
        $run = substr($fragment, 0, $length);
        if ($length < \strlen($fragment) && str_contains(self::QUANTIFIERS, $fragment[$length])) {
            $run = substr($run, 0, -1);
        }
        $cut = strrpos($run, '/');

        return $cut === false ? '' : rtrim(substr($run, 0, $cut), '/');
    }

    /**
     * Steps from the importing directory down to the walk root through the
     * names its parents list, compared byte for byte, so a filesystem that
     * opens `A` for `a` cannot change what the selector sees. Every directory
     * on the way is entered and the root must be a directory.
     *
     * @return array{string, string} [physical path, path relative to the importing directory]
     */
    private function enterRoot(string $physicalBase): array
    {
        $physical = $physicalBase;
        $relative = '';
        foreach (array_filter(explode('/', self::walkRoot($this->selector)), static fn(string $segment): bool => $segment !== '') as $segment) {
            $shown = self::child($this->displayBase, self::child($relative, $segment));
            if (!\in_array($segment, $this->names($physical, self::child($this->displayBase, $relative)), true)) {
                ($this->refuse)(\sprintf('names the directory "%s", which does not exist', $shown));
            }
            $physical = self::child($physical, $segment);
            $relative = self::child($relative, $segment);
            if (!is_dir($physical)) {
                ($this->refuse)(\sprintf(
                    'names "%s", which is not a directory%s',
                    $shown,
                    $this->selector->definition->kind === SelectorKind::Subtree ? '; write a file as a plain string' : '',
                ));
            }
        }

        return [$physical, $relative];
    }

    /**
     * Every directory under every name that leads to it; only a directory that
     * is its own ancestor on the current path (a link back up) ends the descent.
     *
     * @param array<string, true> $ancestors real directories on the path from the walk root
     */
    private function descend(string $physical, string $relative, array $ancestors): void
    {
        $shown = self::child($this->displayBase, $relative);
        [$stat] = NativeCall::attempt(static fn(): array|false => stat($physical));
        if ($stat === false) {
            ($this->refuse)(\sprintf('cannot inspect the directory "%s"', $shown));
        }
        $identity = FileIdentity::fromStat($stat);
        $key = $identity->device . ':' . $identity->inode;
        if (isset($ancestors[$key])) {
            return;
        }
        $ancestors[$key] = true;

        foreach ($this->names($physical, $shown) as $name) {
            $path = self::child($physical, $name);
            $at = self::child($relative, $name);
            if (is_dir($path)) {
                $this->descend($path, $at, $ancestors);
            } elseif (self::isConfigurationName($name) && $this->selects($at)) {
                $this->found[] = [$path, self::child($this->displayBase, $at)];
            }
        }
    }

    /**
     * The names in a directory, in byte order.
     *
     * @return list<string>
     */
    private function names(string $directory, string $shown): array
    {
        $this->assertEnterable($directory, $shown);
        [$names] = NativeCall::attempt(static fn(): array|false => scandir($directory, \SCANDIR_SORT_NONE));
        if ($names === false) {
            ($this->refuse)(\sprintf('needs the contents of the directory "%s", which cannot be listed', $shown));
        }
        $names = array_map(strval(...), array_diff($names, ['.', '..']));
        sort($names, \SORT_STRING);

        return $names;
    }

    private function selects(string $relative): bool
    {
        try {
            return $this->selector->matches(RelativePath::fromString($relative));
        } catch (InvalidArgumentException $failure) {
            ($this->refuse)($failure->getMessage());
        }
    }

    /** Whether a file name ends in a configuration extension, in any letter case. */
    public static function isConfigurationName(string $name): bool
    {
        return \in_array(strtolower(pathinfo($name, \PATHINFO_EXTENSION)), YamlConfigLoader::SUPPORTED_EXTENSIONS, true);
    }

    /** Why a file path cannot be imported for its name alone, or null. */
    public static function nameProblem(string $path): ?string
    {
        return self::isConfigurationName($path)
            ? null
            : \sprintf('is not a %s file', implode(' or ', array_map(static fn(string $extension): string => '.' . $extension, YamlConfigLoader::SUPPORTED_EXTENSIONS)));
    }

    /**
     * A directory is listed and entered by two different permission bits, and a
     * missing one makes every name in it unreachable without any error.
     */
    private function assertEnterable(string $directory, string $shown): void
    {
        if (!is_dir($directory)) {
            return;
        }
        if (!is_readable($directory)) {
            ($this->refuse)(\sprintf('needs the contents of the directory "%s", which cannot be listed', $shown));
        }
        if (!is_executable($directory)) {
            ($this->refuse)(\sprintf('needs to enter the directory "%s", which cannot be entered', $shown));
        }
    }

    private static function child(string $directory, string $name): string
    {
        return match (true) {
            $name === '' => $directory,
            $directory === '.' || $directory === '' => $name,
            default => rtrim($directory, '/') . '/' . $name,
        };
    }
}
