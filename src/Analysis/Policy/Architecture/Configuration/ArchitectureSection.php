<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Configuration;

use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\DocumentSectionSchemaInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\ScalarForm;

/**
 * The `architecture:` section as the configuration document declares it:
 * which keys exist at each level, the form of each value, and how the layers
 * that wrote it combine.
 *
 * `layers` is replaced whole by the last layer that writes it, and each entry
 * is read from that one layer. `allow` merges by source layer name, and the
 * target list of one name is replaced whole. Scalars go to the last writer.
 *
 * Two kinds of value are carried unread for the validators to judge: a
 * criterion (`patterns`, `suffix`, …), written as one string or a list of
 * them, and an allow target, written as a layer name or a long-form map. The
 * keys of a long-form target are therefore recognised by
 * {@see LongFormAllowEntryNormalizer}, not here.
 *
 * An allow source is not judged against the declared layer names here: it may
 * be a glob or captured selector naming layers that exist only after template
 * expansion, so {@see AllowValidator} judges the exact ones after the merge.
 */
final readonly class ArchitectureSection implements DocumentSectionSchemaInterface
{
    public function key(): string
    {
        return ConfigSchema::ARCHITECTURE;
    }

    public function schema(): NodeSchema
    {
        $criteria = [
            'patterns' => NodeSchema::opaque(),
            'suffix' => NodeSchema::opaque(),
            'attributes' => NodeSchema::opaque(),
            'implements' => NodeSchema::opaque(),
            'extends' => NodeSchema::opaque(),
            'match' => NodeSchema::scalar(ScalarForm::String),
        ];

        return NodeSchema::map([
            'layers' => NodeSchema::list(NodeSchema::map([
                'name' => NodeSchema::scalar(ScalarForm::String),
                ...$criteria,
                'pending' => NodeSchema::scalar(ScalarForm::Boolean),
                'exclude' => NodeSchema::map($criteria),
            ])),
            'allow' => NodeSchema::namedMap(NodeSchema::list(NodeSchema::opaque())),
            'coverage-gap' => NodeSchema::scalar(ScalarForm::String),
            'max_expanded_layers' => NodeSchema::scalar(ScalarForm::Integer),
        ]);
    }
}
