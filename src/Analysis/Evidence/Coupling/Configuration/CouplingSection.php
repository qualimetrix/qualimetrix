<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Coupling\Configuration;

use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedValueInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\DocumentSectionSchemaInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\ScalarForm;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\SectionDeclaration;

/** The `coupling:` section: framework selectors replace as one ordered list. */
final readonly class CouplingSection implements DocumentSectionSchemaInterface
{
    public const string KEY = 'coupling';

    public function declaration(): SectionDeclaration
    {
        return new SectionDeclaration(self::KEY, NodeSchema::map([
            'framework_namespaces' => NodeSchema::list(NodeSchema::map([
                'exact' => NodeSchema::scalar(ScalarForm::String),
                'subtree' => NodeSchema::scalar(ScalarForm::String),
                'regex' => NodeSchema::scalar(ScalarForm::String),
            ]))->judgedInEachLayer(static function (ResolvedValueInterface $selectors): void {
                FrameworkNamespaceSelectorParser::parseList($selectors);
            }),
        ]));
    }
}
