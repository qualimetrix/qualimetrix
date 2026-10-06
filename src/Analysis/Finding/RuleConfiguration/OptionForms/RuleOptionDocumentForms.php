<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\RuleConfiguration\OptionForms;

use LogicException;
use Qualimetrix\Analysis\Configuration\ConfigKeySpelling;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\Shorthand;
use Qualimetrix\Analysis\Finding\Contract\Rule\FrameworkOptionKeys;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionAddress;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionDocumentFormsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionKeySet;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionShape;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionSurface;

/** Projects root and level declarations into document schemas. */
final readonly class RuleOptionDocumentForms implements RuleOptionDocumentFormsInterface
{
    public function schema(RuleOptionSurface $surface): NodeSchema
    {
        $set = $surface->ownKeySet();
        $fields = $this->fieldsFor($set, fn(string $key, RuleOptionShape $shape): NodeSchema => $this->rootField($surface, $key, $shape));
        $framework = FrameworkOptionKeys::declared();
        foreach ($framework->acceptedForDisplay() as $key) {
            $shape = $framework->shapeOf(ConfigKeySpelling::normalize($key))
                ?? throw new LogicException('Missing framework option form.');
            $fields[$key] = (new RuleOptionSchemaProjection())->project($shape);
        }

        return $this->schemaFrom($set, $fields)->bareFor('enabled');
    }

    public function schemaAt(RuleOptionSurface $surface, RuleOptionAddress $address): NodeSchema
    {
        $shape = $surface->shapeAt($address);

        return $address->level === null
            ? $this->rootField($surface, $address->key, $shape)
            : (new RuleOptionSchemaProjection())->project($shape);
    }

    /** @param callable(string, RuleOptionShape): NodeSchema $project
     * @return array<string, NodeSchema>
     */
    private function fieldsFor(RuleOptionKeySet $set, callable $project): array
    {
        $fields = [];
        $spreading = self::spreadingTargets($set);
        foreach ($set->acceptedForDisplay() as $key) {
            if (isset($spreading[$key])) {
                continue;
            }
            $shape = $set->shapeOf(ConfigKeySpelling::normalize($key))
                ?? throw new LogicException(\sprintf('Accepted rule option "%s" has no declared form.', $key));
            $fields[$key] = $project($key, $shape);
        }

        return $fields;
    }

    private function rootField(RuleOptionSurface $surface, string $key, RuleOptionShape $shape): NodeSchema
    {
        $slot = $surface->levelNamed($key);
        $projection = new RuleOptionSchemaProjection();
        if ($slot === null) {
            return $projection->project($shape);
        }
        $set = $surface->keySetAtLevel($slot) ?? throw new LogicException('Missing level declaration.');
        $fields = $this->fieldsFor($set, static fn(string $_key, RuleOptionShape $entry): NodeSchema => $projection->project($entry));

        return $projection->project($shape, $this->schemaFrom($set, $fields));
    }

    /** @param array<string, NodeSchema> $fields */
    private function schemaFrom(RuleOptionKeySet $set, array $fields): NodeSchema
    {
        $shorthands = [];
        foreach (self::spreadingTargets($set) as $key => $targets) {
            $shorthands[] = Shorthand::spreading($key, $targets);
        }

        return NodeSchema::map($fields, ...$shorthands)
            ->retiring($set->retired() + \Qualimetrix\Analysis\Configuration\RetiredSuppressionOptions::documentKeys());
    }

    /** @return array<string, non-empty-list<string>> */
    private static function spreadingTargets(RuleOptionKeySet $set): array
    {
        $spreading = $set->spreading();
        foreach ($set->bands() as $band) {
            $spreading[$band->shorthand] = [$band->warning, $band->error];
        }

        return $spreading;
    }
}
