<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\Contract\Rule;

use LogicException;
use Qualimetrix\Analysis\Configuration\ConfigKeySpelling;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\Shorthand;

/**
 * Where a rule option key may be written, and which declaration answers there.
 *
 * A user writes into one of two depths — the producer's own `rules:` block, or
 * a level slot inside it — and the two are answered by two different classes:
 * the rule's own options class, and the level options class
 * {@see HierarchicalRuleOptionsInterface::levelOptionsClasses()} names for that
 * slot. Addressing that pair used to live inside the refusal walk, so the
 * `rules` listing — which has to answer the same question — had no way to ask
 * it and advertised CLI aliases instead, hiding 54 of 132 real options.
 *
 * **It states facts and shapes nothing.** {@see self::writableAt()} is what may
 * legally stand at a depth, which is exactly the refusal's "Options here"; a
 * listing that puts slot names on their own lines subtracts them itself,
 * because which of these facts to print where is presentation and belongs to
 * the printer. An earlier revision of this class carried one method per output
 * shape of each of its two readers, which named the subject "what my callers
 * print" rather than naming a subject at all.
 */
final readonly class RuleOptionSurface
{
    /**
     * @param class-string<RuleOptionsInterface|LevelOptionsInterface> $optionsClass
     * @param array<string, class-string<LevelOptionsInterface>> $levelOptionsClasses slot name => the class answering there
     */
    private function __construct(
        private string $optionsClass,
        private array $levelOptionsClasses,
    ) {}

    /**
     * @param class-string<RuleOptionsInterface|LevelOptionsInterface> $optionsClass
     */
    public static function of(string $optionsClass): self
    {
        return new self(
            $optionsClass,
            is_a($optionsClass, HierarchicalRuleOptionsInterface::class, true)
                ? $optionsClass::levelOptionsClasses()
                : [],
        );
    }

    /**
     * The level slots, in the order they were declared — which is the order the
     * producing rule's own body considers them in, and so the order a reader
     * should meet them.
     *
     * @return list<string>
     */
    public function levels(): array
    {
        return array_map(strval(...), array_keys($this->levelOptionsClasses));
    }

    /** What the rule's own options class declares. */
    public function ownKeySet(): RuleOptionKeySet
    {
        return self::declaredFor($this->optionsClass);
    }

    /**
     * What the class behind one slot declares, or `null` when no slot answers
     * to that name. Split from {@see self::ownKeySet()} rather than folded into
     * one nullable lookup: there is always a declaration at the rule's own
     * depth, and a caller forced to handle a `null` that cannot happen either
     * writes a branch nothing reaches or drops the check.
     */
    public function keySetAtLevel(string $level): ?RuleOptionKeySet
    {
        $slot = $this->levelNamed($level);

        return $slot === null ? null : self::declaredFor($this->levelOptionsClasses[$slot]);
    }

    /**
     * The declared name of the slot $writtenKey names under any spelling, or
     * `null` when no slot answers to it.
     *
     * Public because the refusal walk asks it of every key a user wrote: a
     * second comparison elsewhere is how the two came to disagree — the walk
     * used to match a raw `isset()` against the declared names, which folds one
     * side and not the other.
     */
    public function levelNamed(string $writtenKey): ?string
    {
        $normalized = ConfigKeySpelling::normalize($writtenKey);

        foreach ($this->levelOptionsClasses as $slot => $_) {
            if (ConfigKeySpelling::normalize((string) $slot) === $normalized) {
                return (string) $slot;
            }
        }

        return null;
    }

    /**
     * Every key that may legally be written at this depth, canonical kebab,
     * sorted.
     *
     * At the rule's own depth that is what the class declared — the slot names
     * among them, since a slot is written exactly where an option is — plus the
     * framework keys {@see FrameworkOptionKeys} owns, which no options class
     * declares and which are legal only here. Inside a slot it is that level
     * class's accepted set and nothing else: a framework key written one level
     * down is not a framework key, it is a mistake.
     *
     * @return list<string>
     */
    public function writableAt(?string $level): array
    {
        $keySet = $level === null ? $this->ownKeySet() : $this->keySetAtLevel($level);

        if ($keySet === null) {
            return [];
        }

        $keys = $level === null
            ? [...$keySet->acceptedForDisplay(), ...FrameworkOptionKeys::all()]
            : $keySet->acceptedForDisplay();

        $keys = array_values(array_unique($keys));
        sort($keys);

        return $keys;
    }

    /** The declared document form at an accepted option address. */
    public function schemaAt(RuleOptionAddress $address): NodeSchema
    {
        $set = $address->level === null ? $this->ownKeySet() : $this->keySetAtLevel($address->level);
        $shape = $set?->shapeOf(ConfigKeySpelling::normalize($address->key));

        if ($shape === null && $address->level === null) {
            $shape = FrameworkOptionKeys::declared()->shapeOf(ConfigKeySpelling::normalize($address->key));
        }

        if ($shape === null) {
            throw new LogicException(\sprintf('Rule option "%s" has no declared document form.', $address->written()));
        }

        $slot = $address->level === null ? $this->levelNamed($address->key) : null;
        if ($slot === null) {
            return $shape->asNodeSchema();
        }

        return $shape->asNodeSchema($this->schemaOf($this->keySetAtLevel($slot) ?? throw new LogicException('Missing level declaration.')));
    }

    /** @param class-string<RuleOptionsInterface|LevelOptionsInterface> $optionsClass */
    public static function declaredFor(string $optionsClass): RuleOptionKeySet
    {
        return $optionsClass::acceptedOptionKeys();
    }

    /** @param class-string<RuleOptionsInterface|LevelOptionsInterface> $optionsClass */
    public static function bandFor(string $optionsClass, string $shorthand): RuleOptionBand
    {
        foreach (self::declaredFor($optionsClass)->bands() as $band) {
            if ($band->shorthand === $shorthand) {
                return $band;
            }
        }
        throw new LogicException(\sprintf('Options class "%s" declares no band "%s".', $optionsClass, $shorthand));
    }

    /** The complete producer entry; every shorthand is expanded by the document engine. */
    public function schema(): NodeSchema
    {
        return $this->schemaOf($this->ownKeySet(), true)->bareFor('enabled');
    }

    private function schemaOf(RuleOptionKeySet $set, bool $root = false): NodeSchema
    {
        $fields = [];
        $spreading = $set->spreading();
        foreach ($set->bands() as $band) {
            $spreading[$band->shorthand] = [$band->warning, $band->error];
        }
        foreach ($set->acceptedForDisplay() as $key) {
            if (isset($spreading[$key])) {
                continue;
            }
            $shape = $set->shapeOf(ConfigKeySpelling::normalize($key))
                ?? throw new LogicException(\sprintf('Accepted rule option "%s" has no declared form.', $key));
            $slot = $root ? $this->levelNamed($key) : null;
            $fields[$key] = $slot === null ? $shape->asNodeSchema()
                : $shape->asNodeSchema($this->schemaOf($this->keySetAtLevel($slot) ?? throw new LogicException('Missing level declaration.')));
        }
        if ($root) {
            $framework = FrameworkOptionKeys::declared();
            foreach ($framework->acceptedForDisplay() as $key) {
                $fields[$key] = $framework->shapeOf(ConfigKeySpelling::normalize($key))?->asNodeSchema()
                    ?? throw new LogicException('Missing framework option form.');
            }
        }
        $shorthands = [];
        foreach ($spreading as $key => $targets) {
            $shorthands[] = Shorthand::spreading($key, $targets);
        }
        return NodeSchema::map($fields, ...$shorthands)->retiring($set->retired() + \Qualimetrix\Analysis\Configuration\RetiredSuppressionOptions::documentKeys());
    }

    /**
     * Where a freely authored option target lands — the spelling a CLI alias
     * carries, which is written by hand in an attribute and is kebab, snake or
     * camel depending on who wrote it, dotted when it addresses a slot.
     *
     * Null covers three cases on purpose, because a caller joining an alias to
     * a declaration has no use for the difference between them. A key nothing
     * here knows. A key the class recognises only in order to answer about it
     * in its own words — the class's to speak for, not an alias's to reach. And
     * a framework key, which {@see self::writableAt()} does report as writable
     * at the rule's own depth: it is legal there, but no options class declares
     * it and no alias targets one, because the factory takes all three out of
     * the configuration before any rule is built. The asymmetry with
     * `writableAt()` is the point rather than an oversight — one method answers
     * "may a user write this here", the other "which declaration owns this".
     */
    public function locate(string $target): ?RuleOptionAddress
    {
        $parts = explode('.', $target, 2);

        if (\count($parts) === 2) {
            $slot = $this->levelNamed($parts[0]);

            if ($slot !== null) {
                return $this->addressIn($slot, $parts[1]);
            }
        }

        return $this->addressIn(null, $target);
    }

    private function addressIn(?string $level, string $key): ?RuleOptionAddress
    {
        $keySet = $level === null ? $this->ownKeySet() : $this->keySetAtLevel($level);
        $spelling = $keySet?->spellingOf(ConfigKeySpelling::normalize($key));
        if ($spelling === null && $level === null) {
            $spelling = FrameworkOptionKeys::declared()->spellingOf(ConfigKeySpelling::normalize($key));
        }

        return $spelling === null ? null : new RuleOptionAddress($level, $spelling);
    }
}
