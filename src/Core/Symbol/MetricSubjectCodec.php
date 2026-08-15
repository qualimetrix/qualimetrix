<?php

declare(strict_types=1);

namespace Qualimetrix\Core\Symbol;

use InvalidArgumentException;

use Qualimetrix\Analysis\Evidence\Measurement\DataBag;
use Qualimetrix\Core\Path\RelativePath;

/**
 * Scalar wire codec for a MetricSubject stored in a DataBag entry.
 *
 * The file is deliberately supplied by the rule that reads the entry: a
 * collector must never serialize paths or identity objects through IPC.
 */
final class MetricSubjectCodec
{
    private const array ENTRY_KEYS = [
        'subjectKind' => true,
        'logicalKind' => true,
        'namespace' => true,
        'class' => true,
        'member' => true,
        'startFilePos' => true,
        'collisionOrdinal' => true,
    ];

    /** @return array<string, int|string> */
    public static function encodeFile(): array
    {
        return ['subjectKind' => 'file'];
    }

    /** @return array<string, int|string> */
    public static function encodeClass(string $namespace, string $class, int $startFilePos, ?int $collisionOrdinal = null): array
    {
        return self::declaration(
            ['logicalKind' => 'class', 'namespace' => $namespace, 'class' => $class, 'startFilePos' => $startFilePos],
            $collisionOrdinal,
        );
    }

    /** @return array<string, int|string> */
    public static function encodeMethod(string $namespace, string $class, string $member, int $startFilePos, ?int $collisionOrdinal = null): array
    {
        return self::declaration(
            ['logicalKind' => 'method', 'namespace' => $namespace, 'class' => $class, 'member' => $member, 'startFilePos' => $startFilePos],
            $collisionOrdinal,
        );
    }

    /** @return array<string, int|string> */
    public static function encodeFunction(string $namespace, string $member, int $startFilePos, ?int $collisionOrdinal = null): array
    {
        return self::declaration(
            ['logicalKind' => 'function', 'namespace' => $namespace, 'member' => $member, 'startFilePos' => $startFilePos],
            $collisionOrdinal,
        );
    }

    /** @param array<string, scalar> $entry */
    public static function decodeEntry(array $entry, RelativePath $containerFile): MetricSubject
    {
        $components = array_filter(
            array_intersect_key($entry, self::ENTRY_KEYS),
            static fn($value): bool => \is_int($value) || \is_string($value),
        );

        return self::decode($components, $containerFile);
    }

    /**
     * @param array<string, int|string> $components
     *
     * @qmx-threshold complexity.cyclomatic warning=13 error=13 — Canonical finite wire grammar keeps four closed subject shapes and exact validation together.
     * @qmx-threshold complexity.npath warning=289 error=289 — Canonical finite wire grammar keeps four closed subject shapes and exact validation together.
     */
    public static function decode(array $components, RelativePath $containerFile): MetricSubject
    {
        self::assertScalarComponents($components);

        $subjectKind = self::requireString($components, 'subjectKind');
        if ($subjectKind === 'file') {
            self::assertExactKeys($components, ['subjectKind']);

            return MetricSubject::aggregate(SymbolPath::forFile($containerFile));
        }

        if ($subjectKind !== 'declaration') {
            throw new InvalidArgumentException('Metric subject component subjectKind must be file or declaration');
        }

        $logicalKind = self::requireString($components, 'logicalKind');
        [$required, $allowed] = match ($logicalKind) {
            'class' => [
                ['subjectKind', 'logicalKind', 'namespace', 'class', 'startFilePos'],
                ['subjectKind', 'logicalKind', 'namespace', 'class', 'startFilePos', 'collisionOrdinal'],
            ],
            'method' => [
                ['subjectKind', 'logicalKind', 'namespace', 'class', 'member', 'startFilePos'],
                ['subjectKind', 'logicalKind', 'namespace', 'class', 'member', 'startFilePos', 'collisionOrdinal'],
            ],
            'function' => [
                ['subjectKind', 'logicalKind', 'namespace', 'member', 'startFilePos'],
                ['subjectKind', 'logicalKind', 'namespace', 'member', 'startFilePos', 'collisionOrdinal'],
            ],
            default => throw new InvalidArgumentException('Metric subject component logicalKind must be class, method, or function'),
        };
        self::assertKnownKeys($components, $allowed);
        foreach ($required as $key) {
            if (!\array_key_exists($key, $components)) {
                throw new InvalidArgumentException(\sprintf('Missing metric subject component "%s"', $key));
            }
        }

        $namespace = self::requireString($components, 'namespace');
        $startFilePos = self::requireNonNegativeInt($components, 'startFilePos');
        $ordinal = \array_key_exists('collisionOrdinal', $components)
            ? self::requireNonNegativeInt($components, 'collisionOrdinal')
            : null;

        $logical = match ($logicalKind) {
            'class' => SymbolPath::forClass($namespace, self::requireNonEmptyString($components, 'class')),
            'method' => SymbolPath::forMethod(
                $namespace,
                self::requireNonEmptyString($components, 'class'),
                self::requireNonEmptyString($components, 'member'),
            ),
            'function' => SymbolPath::forGlobalFunction($namespace, self::requireNonEmptyString($components, 'member')),
        };

        return MetricSubject::declaration(new DeclarationPath($logical, $containerFile, $startFilePos, $ordinal));
    }

    /**
     * @param array<string, int|string> $fields
     *
     * @return array<string, int|string>
     */
    private static function declaration(array $fields, ?int $collisionOrdinal): array
    {
        if ($collisionOrdinal !== null && $collisionOrdinal < 0) {
            throw new InvalidArgumentException('Metric subject collision ordinal must not be negative');
        }

        $components = ['subjectKind' => 'declaration', ...$fields];
        if ($collisionOrdinal !== null) {
            $components['collisionOrdinal'] = $collisionOrdinal;
        }

        return $components;
    }

    /** @param array<string, mixed> $components */
    private static function assertScalarComponents(array $components): void
    {
        foreach ($components as $key => $value) {
            if (!\is_string($key) || (!\is_int($value) && !\is_string($value))) {
                throw new InvalidArgumentException('Metric subject components must have string keys and int|string values');
            }
        }
    }

    /**
     * @param array<string, int|string> $components
     * @param list<string> $allowed
     */
    private static function assertKnownKeys(array $components, array $allowed): void
    {
        foreach ($components as $key => $_) {
            if (!\in_array($key, $allowed, true)) {
                throw new InvalidArgumentException(\sprintf('Unknown or forbidden metric subject component "%s"', $key));
            }
        }
    }

    /**
     * @param array<string, int|string> $components
     * @param list<string> $expected
     */
    private static function assertExactKeys(array $components, array $expected): void
    {
        self::assertKnownKeys($components, $expected);
        foreach ($expected as $key) {
            if (!\array_key_exists($key, $components)) {
                throw new InvalidArgumentException(\sprintf('Missing metric subject component "%s"', $key));
            }
        }
    }

    /** @param array<string, int|string> $components */
    private static function requireString(array $components, string $key): string
    {
        if (!\array_key_exists($key, $components) || !\is_string($components[$key])) {
            throw new InvalidArgumentException(\sprintf('Metric subject component "%s" must be a string', $key));
        }

        return $components[$key];
    }

    /** @param array<string, int|string> $components */
    private static function requireNonEmptyString(array $components, string $key): string
    {
        $value = self::requireString($components, $key);
        if ($value === '') {
            throw new InvalidArgumentException(\sprintf('Metric subject component "%s" must not be empty', $key));
        }

        return $value;
    }

    /** @param array<string, int|string> $components */
    private static function requireNonNegativeInt(array $components, string $key): int
    {
        if (!\array_key_exists($key, $components) || !\is_int($components[$key]) || $components[$key] < 0) {
            throw new InvalidArgumentException(\sprintf('Metric subject component "%s" must be a non-negative integer', $key));
        }

        return $components[$key];
    }
}
