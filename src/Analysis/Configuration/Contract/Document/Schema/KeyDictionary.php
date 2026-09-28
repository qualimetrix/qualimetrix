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
        $spreadTo = [];
        foreach ($shorthands as $shorthand) {
            self::assertCanonical($shorthand->key);
            if (isset($fields[$shorthand->key])) {
                throw new LogicException(\sprintf('Shorthand "%s" is also declared as a key of the same map.', $shorthand->key));
            }

            $spreadTo = self::claimTargets($spreadTo, $shorthand, $fields);
            $byKey[$shorthand->key] = $shorthand;
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
     *
     * @return array<string, string>
     */
    private static function claimTargets(array $spreadTo, Shorthand $shorthand, array $fields): array
    {
        foreach ($shorthand->targets as $target) {
            if (!isset($fields[$target])) {
                throw new LogicException(\sprintf('Shorthand "%s" spreads to "%s", which the map does not declare.', $shorthand->key, $target));
            }

            if (isset($spreadTo[$target])) {
                throw new LogicException(\sprintf('Shorthands "%s" and "%s" both spread to "%s".', $spreadTo[$target], $shorthand->key, $target));
            }

            $spreadTo[$target] = $shorthand->key;
        }

        return $spreadTo;
    }

    private static function assertCanonical(string $key): void
    {
        if (!\in_array($key, ConfigKeySpelling::acceptedSpellings($key), true) || strtolower($key) !== $key) {
            throw new LogicException(\sprintf('Schema key "%s" is not canonical: lowercase words joined by "_" or "-".', $key));
        }
    }
}
