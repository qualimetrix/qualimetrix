<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling\Configuration;

use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\DocumentSectionSchemaInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\ScalarForm;

/** The `coupling:` section: framework selectors replace as one ordered list. */
final readonly class CouplingSection implements DocumentSectionSchemaInterface
{
    public const string KEY = 'coupling';

    public function key(): string
    {
        return self::KEY;
    }

    public function schema(): NodeSchema
    {
        return NodeSchema::map([
            'framework_namespaces' => NodeSchema::list(NodeSchema::map([
                'exact' => NodeSchema::scalar(ScalarForm::String),
                'subtree' => NodeSchema::scalar(ScalarForm::String),
                'regex' => NodeSchema::scalar(ScalarForm::String),
            ])->judgedInEachLayer(static function (ResolvedValueInterface $selector): void {
                $value = $selector->plain();
                if (!\is_array($value) || \count($value) !== 1) {
                    $selector->refuse('Framework namespace selectors must be one-entry mappings: {exact: value}, {subtree: value}, or {regex: value}.');
                }
            })),
        ]);
    }
}
