<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Document;

use LogicException;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedBareName;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedList;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedMap;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedOpaque;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedScalar;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\MergePolicy;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\ScalarForm;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;

/**
 * Phase 1, one layer: recognises every dictionary key, judges the form of
 * every written value, expands shorthands, and drops `~` as unwritten. What
 * it returns carries that layer's provenance and is ready to merge.
 *
 * A name whose vocabulary comes from a sibling node is collected for phase 3
 * instead of judged here. A named entry written without a body is kept as a
 * {@see ResolvedBareName}: dropping it would take the name out of every
 * judgement after this one.
 */
final class LayerReading
{
    /** @var list<PendingName> */
    private array $pendingNames = [];

    public function __construct(private readonly bool $admitsUndeclaredRoots = false) {}

    /**
     * @throws ConfigurationRefusal
     */
    public function readRoot(NodeSchema $root, AuthoredLayer $layer): ?ResolvedValueInterface
    {
        return $this->readMap($root, $layer->root, ReadingContext::of($layer), $this->admitsUndeclaredRoots);
    }

    /** @return list<PendingName> */
    public function pendingNames(): array
    {
        return $this->pendingNames;
    }

    private function read(NodeSchema $schema, AuthoredNode $node, ReadingContext $at): ?ResolvedValueInterface
    {
        return match ($schema->policy) {
            MergePolicy::LastWriterWins => $this->readScalar($schema, $node, $at),
            MergePolicy::DeepMerge => $this->readMap($schema, $node, $at),
            MergePolicy::Replace, MergePolicy::Accumulate => $this->readList($schema, $node, $at),
            MergePolicy::ByName => $this->readNamedMap($schema, $node, $at),
            MergePolicy::PerLayer => $node->isUnwritten() ? null : self::opaque($node, $at),
        };
    }

    private function readScalar(NodeSchema $schema, AuthoredNode $node, ReadingContext $at): ?ResolvedScalar
    {
        if ($node->isUnwritten()) {
            return null;
        }

        $forms = $schema->scalarForms();
        $expected = $forms === [] ? 'a scalar' : implode(' or ', array_map(static fn(ScalarForm $form): string => $form->value, $forms));

        if ($node->shape !== AuthoredShape::Scalar || $node->scalar === null) {
            throw $at->refusal(self::hinted(\sprintf('%s must be %s, got %s.', ucfirst($at->where()), $expected, self::shapeName($node)), $schema));
        }

        foreach ($forms as $form) {
            if ($form->accepts($node->scalar)) {
                return new ResolvedScalar($node->scalar, $at->provenance($node));
            }
        }

        if ($forms !== []) {
            throw $at->refusal(self::hinted(\sprintf('%s must be %s, got %s.', ucfirst($at->where()), $expected, get_debug_type($node->scalar)), $schema));
        }

        return new ResolvedScalar($node->scalar, $at->provenance($node));
    }

    private function readMap(NodeSchema $schema, AuthoredNode $node, ReadingContext $at, bool $admitUndeclared = false): ?ResolvedMap
    {
        if (!$this->isMapShaped($schema, $node, $at)) {
            return null;
        }

        $fields = $schema->fields();
        $shorthands = [];
        foreach ($schema->shorthands() as $shorthand) {
            $shorthands[$shorthand->key] = $shorthand;
        }

        $dictionary = [...array_keys($fields), ...array_keys($shorthands)];
        $claimed = [];
        $entries = [];
        $shorthandNodes = [];

        foreach ($node->children as $writtenKey => $child) {
            $written = (string) $writtenKey;
            $canonical = KeyRecognition::recognise($written, $dictionary, $at->child($written, $written, $child), $admitUndeclared);

            if ($canonical === null) {
                if (!$child->isUnwritten()) {
                    $entries[$written] = self::opaque($child, $at->child($written, $written, $child));
                }

                continue;
            }

            self::claim($claimed, $canonical, $written, $at);

            if ($child->isUnwritten()) {
                continue;
            }

            if (isset($shorthands[$canonical])) {
                $shorthandNodes[$canonical] = [$written, $child];

                continue;
            }

            $value = $this->read($fields[$canonical], $child, $at->child($written, $canonical, $child));
            if ($value !== null) {
                $entries[$canonical] = $value;
            }
        }

        foreach ($shorthandNodes as $key => [$written, $child]) {
            foreach ($shorthands[$key]->targets as $target) {
                if (\array_key_exists($target, $entries)) {
                    throw $at->child($written, $key, $child)->refusal(\sprintf(
                        '%s writes both "%s" and "%s"; "%s" is shorthand for %s — write either the shorthand or the full keys in one layer.',
                        ucfirst($at->where()),
                        $written,
                        $claimed[$target],
                        $key,
                        '"' . implode('" and "', $shorthands[$key]->targets) . '"',
                    ));
                }
            }

            foreach ($shorthands[$key]->targets as $target) {
                $value = $this->read($fields[$target], $child, $at->child($written, $target, $child));
                if ($value !== null) {
                    $entries[$target] = $value;
                }
            }
        }

        return $entries === [] ? null : new ResolvedMap($entries, [$at->provenance($node)]);
    }

    private function readNamedMap(NodeSchema $schema, AuthoredNode $node, ReadingContext $at): ?ResolvedMap
    {
        if (!$this->isMapShaped($schema, $node, $at)) {
            return null;
        }

        $vocabulary = $schema->names();
        if ($vocabulary?->isFromSibling() === true && $at->insideList) {
            throw new LogicException(\sprintf('"%s": a name vocabulary drawn from a sibling cannot be judged inside a list item.', implode('.', $at->canonicalPath)));
        }

        $claimed = [];
        $entries = [];

        foreach ($node->children as $writtenKey => $child) {
            $written = (string) $writtenKey;
            $childAt = $at->child($written, $written, $child);
            $name = $written;

            if ($vocabulary?->isFixed() === true) {
                $name = (string) KeyRecognition::recognise($written, $vocabulary->fixedNames(), $childAt);
                self::claim($claimed, $name, $written, $at);
            } elseif ($vocabulary?->isPredicate() === true) {
                $refused = $vocabulary->refuse($written);
                if ($refused !== null) {
                    throw $childAt->refusal($refused->summary, $written, $refused->accepted);
                }
            } elseif ($vocabulary !== null) {
                $this->pendingNames[] = new PendingName($at->canonicalPath, $written, $vocabulary, $childAt->provenance($child));
            }

            $value = $child->isUnwritten() ? null : $this->read($schema->element(), $child, $at->child($written, $name, $child));
            $entries[$name] = $value ?? new ResolvedBareName([$childAt->provenance($child)]);
        }

        return $entries === [] ? null : new ResolvedMap($entries, [$at->provenance($node)]);
    }

    private function readList(NodeSchema $schema, AuthoredNode $node, ReadingContext $at): ?ResolvedList
    {
        if ($node->isUnwritten()) {
            return null;
        }

        if ($node->shape === AuthoredShape::Scalar || $node->shape === AuthoredShape::Mapping) {
            throw $at->refusal(self::hinted(\sprintf('%s must be a list, got %s.', ucfirst($at->where()), self::shapeName($node)), $schema));
        }

        $element = $schema->element();
        $items = [];

        foreach ($node->children as $index => $child) {
            $itemAt = $at->child((string) $index, (string) $index, $child, true);

            if ($element->policy === MergePolicy::PerLayer) {
                $items[] = self::opaque($child, $itemAt);

                continue;
            }

            if ($child->isUnwritten()) {
                throw $itemAt->refusal(\sprintf(
                    'Item %d of %s is null (`~`); a list item is a value, not an unwritten key — remove it or write a value.',
                    $index,
                    $at->where(),
                ));
            }

            // Unwritten nodes are refused above, so null here is an item that
            // is an empty map, or a map of nothing but `~`: dropping it would
            // shorten the list without a trace.
            $items[] = $this->read($element, $child, $itemAt) ?? throw $itemAt->refusal(\sprintf(
                'Item %d of %s writes nothing; remove it or give it a value.',
                $index,
                $at->where(),
            ));
        }

        return new ResolvedList($items, [$at->provenance($node)]);
    }

    /** False for `~`; refuses a scalar or a non-empty list where a map is declared. */
    private function isMapShaped(NodeSchema $schema, AuthoredNode $node, ReadingContext $at): bool
    {
        if ($node->isUnwritten()) {
            return false;
        }

        if ($node->shape === AuthoredShape::Scalar || ($node->shape === AuthoredShape::Sequence && $node->children !== [])) {
            throw $at->refusal(self::hinted(\sprintf('%s must be a map, got %s.', ucfirst($at->where()), self::shapeName($node)), $schema));
        }

        return true;
    }

    private static function hinted(string $refusal, NodeSchema $schema): string
    {
        $hint = $schema->hint();

        return $hint === null ? $refusal : $refusal . ' ' . $hint;
    }

    /**
     * @param array<string, string> $claimed canonical key => the spelling that claimed it
     */
    private static function claim(array &$claimed, string $canonical, string $written, ReadingContext $at): void
    {
        $first = $claimed[$canonical] ?? null;
        $claimed[$canonical] = $first ?? $written;

        if ($first !== null) {
            throw $at->child($written, $canonical, AuthoredNode::scalar(null))->refusal(\sprintf(
                'Keys "%s" and "%s" in %s are two spellings of one key, and a layer may set it only once. Keep one of them.',
                $first,
                $written,
                $at->where(),
            ));
        }
    }

    private static function opaque(AuthoredNode $node, ReadingContext $at): ResolvedOpaque
    {
        return new ResolvedOpaque([['provenance' => $at->provenance($node), 'value' => $node->plain()]]);
    }

    private static function shapeName(AuthoredNode $node): string
    {
        return match ($node->shape) {
            AuthoredShape::Scalar => $node->scalar === null ? 'null' : get_debug_type($node->scalar),
            AuthoredShape::Mapping => 'a map',
            AuthoredShape::Sequence => 'a list',
            AuthoredShape::EmptyCollection => 'an empty collection',
        };
    }
}
