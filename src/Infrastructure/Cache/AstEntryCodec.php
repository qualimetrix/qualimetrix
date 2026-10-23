<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Cache;

use Closure;
use Composer\InstalledVersions;
use FilesystemIterator;
use PhpParser\Comment;
use PhpParser\Node;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplObjectStorage;
use Throwable;

final class AstEntryCodec
{
    /** Increase whenever encoding, parser construction/options/lexer or pre-write AST processing changes. */
    public const int FORMAT_VERSION = 1;

    /** Match PHP's default unserialize_max_depth and pass it explicitly on read. */
    private const int MAX_DEPTH = 4096;

    /** @var ?list<class-string> */
    private static ?array $classes = null;

    /**
     * @param list<Node> $nodes
     *
     * @return ?string Null for a tree beyond the depth bound or with an active cycle.
     */
    public function encode(array $nodes): ?string
    {
        if (!$this->walk($nodes, null)) {
            return null;
        }

        $precision = \ini_get('serialize_precision');
        ini_set('serialize_precision', '-1');
        try {
            return serialize($nodes);
        } finally {
            if ($precision !== false) {
                ini_set('serialize_precision', $precision);
            }
        }
    }

    /** @return ?list<Node> */
    public function decode(string $bytes): ?array
    {
        try {
            $nodes = @unserialize($bytes, ['allowed_classes' => self::allowedClasses(), 'max_depth' => self::MAX_DEPTH]);
            if (!\is_array($nodes) || !array_is_list($nodes)) {
                return null;
            }
            foreach ($nodes as $node) {
                if (!$node instanceof Node) {
                    return null;
                }
            }
            if (!$this->walk($nodes, $this->validObject(...))) {
                return null;
            }

            return $nodes;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<class-string> */
    public static function allowedClasses(): array
    {
        if (self::$classes !== null) {
            return self::$classes;
        }
        $installation = InstalledVersions::getInstallPath('nikic/php-parser');
        if ($installation === null) {
            return self::$classes = [];
        }
        $root = $installation . '/lib/PhpParser';
        $classes = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $class = 'PhpParser\\' . str_replace('/', '\\', substr($file->getPathname(), \strlen($root) + 1, -4));
            if (self::allowedClass($class)) {
                $classes[] = $class;
            }
        }
        sort($classes);

        return self::$classes = $classes;
    }

    /** @phpstan-assert-if-true class-string $class */
    private static function allowedClass(string $class): bool
    {
        return class_exists($class)
            && (is_a($class, Node::class, true) || is_a($class, Comment::class, true))
            && !(new ReflectionClass($class))->isAbstract();
    }

    /** @param ?Closure(object): bool $validator */
    private function walk(mixed $root, ?Closure $validator): bool
    {
        $visited = new SplObjectStorage();
        $stack = [[$root, 0, false]];
        while ($stack !== []) {
            [$value, $depth, $leaving] = array_pop($stack);
            if ($leaving) {
                $visited->offsetSet($value, true);
                continue;
            }
            if ($depth > self::MAX_DEPTH) {
                return false;
            }
            if (\is_object($value) && !$this->queueObject($value, $depth, $visited, $stack, $validator)) {
                return false;
            }
            if (\is_array($value)) {
                self::queueChildren($value, $depth, $stack);
            } elseif ($validator !== null && \is_resource($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param SplObjectStorage<object, bool> $visited
     * @param list<array{mixed, int, bool}> $stack
     * @param ?Closure(object): bool $validator
     */
    private function queueObject(object $value, int $depth, SplObjectStorage $visited, array &$stack, ?Closure $validator): bool
    {
        if ($visited->offsetExists($value)) {
            return $visited->offsetGet($value) === true;
        }
        if ($validator !== null && !$validator($value)) {
            return false;
        }
        $visited->offsetSet($value, false);
        $stack[] = [$value, $depth, true];
        $stack[] = [(array) $value, $depth + 1, false];

        return true;
    }

    /**
     * @param array<array-key, mixed> $value
     * @param list<array{mixed, int, bool}> $stack
     */
    private static function queueChildren(array $value, int $depth, array &$stack): void
    {
        foreach (array_reverse($value) as $child) {
            $stack[] = [$child, $depth + 1, false];
        }
    }

    private function validObject(object $value): bool
    {
        if (!$value instanceof Node && !$value instanceof Comment) {
            return false;
        }
        if ($value instanceof Comment) {
            return $this->initializedComment($value);
        }

        return $this->initializedNode($value);
    }

    private function initializedComment(Comment $comment): bool
    {
        foreach ((new ReflectionClass($comment))->getProperties() as $property) {
            if (!$property->isInitialized($comment)) {
                return false;
            }
        }

        return true;
    }

    private function initializedNode(Node $node): bool
    {
        $node->getAttributes();
        $reflection = new ReflectionClass($node);
        foreach ($node->getSubNodeNames() as $name) {
            if (!$reflection->getProperty($name)->isInitialized($node)) {
                return false;
            }
        }

        return true;
    }
}
