<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Cache;

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

    /** @var ?list<class-string> */
    private static ?array $classes = null;

    /** @param list<Node> $nodes */
    public function encode(array $nodes): string
    {
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
            $nodes = @unserialize($bytes, ['allowed_classes' => self::allowedClasses()]);
            if (!\is_array($nodes) || !array_is_list($nodes)) {
                return null;
            }
            foreach ($nodes as $node) {
                if (!$node instanceof Node) {
                    return null;
                }
            }
            if (!$this->validValue($nodes, new SplObjectStorage(), 0)) {
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

    /** @param SplObjectStorage<object, bool> $visited */
    private function validValue(mixed $value, SplObjectStorage $visited, int $depth): bool
    {
        if ($depth > 512) {
            return false;
        }
        if (\is_object($value)) {
            return $this->validObject($value, $visited, $depth);
        }
        if (\is_array($value)) {
            foreach ($value as $child) {
                if (!$this->validValue($child, $visited, $depth + 1)) {
                    return false;
                }
            }
        }

        return !\is_resource($value);
    }

    /** @param SplObjectStorage<object, bool> $visited */
    private function validObject(object $value, SplObjectStorage $visited, int $depth): bool
    {
        if (!$value instanceof Node && !$value instanceof Comment) {
            return false;
        }
        if ($visited->offsetExists($value)) {
            return $visited->offsetGet($value);
        }
        $visited->offsetSet($value, false);
        if ($value instanceof Comment && !$this->initializedComment($value)) {
            return false;
        }
        if ($value instanceof Node && !$this->initializedNode($value)) {
            return false;
        }

        $valid = $this->validValue((array) $value, $visited, $depth + 1);
        $visited->offsetSet($value, $valid);

        return $valid;
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
