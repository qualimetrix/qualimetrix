<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Contract\Document\Schema;

use LogicException;
use Qualimetrix\Analysis\Configuration\ConfigKeySpelling;

/**
 * The keys one map declares: its fields, each with the schema of its value,
 * and the shorthands that stand for several of them.
 */
final readonly class KeyDictionary
{
    /**
     * @param array<string, NodeSchema> $fields
     * @param array<string, Shorthand> $shorthands shorthand key => shorthand
     */
    private function __construct(
        private array $fields,
        private array $shorthands,
    ) {}

    public static function none(): self
    {
        return new self([], []);
    }

    /**
     * @param array<string, NodeSchema> $fields canonical key => schema
     * @param list<Shorthand> $shorthands
     */
    public static function of(array $fields, array $shorthands): self
    {
        foreach (array_keys($fields) as $key) {
            self::assertCanonical($key);
        }

        $byKey = [];
        foreach ($shorthands as $shorthand) {
            self::assertCanonical($shorthand->key);
            if (isset($fields[$shorthand->key])) {
                throw new LogicException(\sprintf('Shorthand "%s" is also declared as a key of the same map.', $shorthand->key));
            }

            $byKey[$shorthand->key] = $shorthand;
        }

        $spreadTo = [];
        foreach ($shorthands as $shorthand) {
            $spreadTo = self::claimTargets($spreadTo, $shorthand, $fields, $byKey);
        }
        foreach ($byKey as $key => $_shorthand) {
            self::assertAcyclic($key, $byKey, []);
        }

        return new self($fields, $byKey);
    }

    /**
     * Every key an author may write here, fields first.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return [...array_keys($this->fields), ...array_keys($this->shorthands)];
    }

    /** @return array<string, NodeSchema> */
    public function fields(): array
    {
        return $this->fields;
    }

    /** @return list<Shorthand> */
    public function shorthands(): array
    {
        return array_values($this->shorthands);
    }

    public function shorthand(string $key): ?Shorthand
    {
        return $this->shorthands[$key] ?? null;
    }

    /**
     * @param array<string, string> $spreadTo target => the shorthand that spreads to it
     * @param array<string, NodeSchema> $fields
     * @param array<string, Shorthand> $shorthands
     *
     * @return array<string, string>
     */
    private static function claimTargets(array $spreadTo, Shorthand $shorthand, array $fields, array $shorthands): array
    {
        foreach ($shorthand->targets as $target) {
            self::assertTargetPath($shorthand->key, $target, $fields, $shorthands);

            if (isset($spreadTo[$target])) {
                throw new LogicException(\sprintf('Shorthands "%s" and "%s" both spread to "%s".', $spreadTo[$target], $shorthand->key, $target));
            }

            $spreadTo[$target] = $shorthand->key;
        }

        return $spreadTo;
    }

    /**
     * @param array<string, NodeSchema> $fields
     * @param array<string, Shorthand> $shorthands
     */
    private static function assertTargetPath(string $source, string $target, array $fields, array $shorthands): void
    {
        $segments = explode('.', $target);
        foreach ($segments as $index => $segment) {
            self::assertCanonical($segment);
            $last = $index === \count($segments) - 1;
            if (isset($shorthands[$segment])) {
                if ($last) {
                    return;
                }
                break;
            }
            $field = $fields[$segment] ?? null;
            if ($field === null) {
                break;
            }
            if ($last) {
                return;
            }
            if ($field->policy !== MergePolicy::DeepMerge) {
                break;
            }
            $fields = $field->fields();
            $shorthands = array_combine(
                array_map(static fn(Shorthand $item): string => $item->key, $field->shorthands()),
                $field->shorthands(),
            );
        }

        throw new LogicException(\sprintf('Shorthand "%s" spreads to "%s", which the map does not declare.', $source, $target));
    }

    /**
     * @param array<string, Shorthand> $shorthands
     * @param array<string, true> $visiting
     */
    private static function assertAcyclic(string $key, array $shorthands, array $visiting): void
    {
        if (isset($visiting[$key])) {
            throw new LogicException(\sprintf('Shorthand cycle includes "%s".', $key));
        }
        $visiting[$key] = true;
        foreach ($shorthands[$key]->targets as $target) {
            if (isset($shorthands[$target])) {
                self::assertAcyclic($target, $shorthands, $visiting);
            }
        }
    }

    public static function assertCanonical(string $key): void
    {
        if (!\in_array($key, ConfigKeySpelling::acceptedSpellings($key), true) || strtolower($key) !== $key) {
            throw new LogicException(\sprintf('Schema key "%s" is not canonical: lowercase words joined by "_" or "-".', $key));
        }
    }
}
