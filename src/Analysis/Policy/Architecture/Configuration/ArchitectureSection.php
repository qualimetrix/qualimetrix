<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Architecture\Configuration;

use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\DocumentSectionSchemaInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NameVocabulary;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\ScalarForm;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\Allow\InvalidSelectorException;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\Allow\LayerSelectorParser;

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
 * An allow source is judged against the layer names `layers` declares once
 * every layer is merged, so a preset's layer is known to the file that allows
 * it. Only an exact name must be declared: a glob or captured selector names
 * layers that exist only after template expansion, and a malformed selector
 * is refused by {@see AllowValidator} in the words of the selector grammar.
 */
final readonly class ArchitectureSection implements DocumentSectionSchemaInterface
{
    public const string KEY = 'architecture';

    public function key(): string
    {
        return self::KEY;
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
            'allow' => NodeSchema::namedMap(
                NodeSchema::list(NodeSchema::opaque()),
                NameVocabulary::fromSibling('layers', self::layerNames(...), self::refersToALayer(...)),
            ),
            'coverage-gap' => NodeSchema::scalar(ScalarForm::String),
            'max_expanded_layers' => NodeSchema::scalar(ScalarForm::Integer),
        ]);
    }

    /**
     * The names the merged `layers` declares; an entry without a string name
     * is {@see LayersValidator}'s to refuse.
     *
     * @return list<string>
     */
    private static function layerNames(mixed $layers): array
    {
        $names = [];
        foreach (\is_array($layers) ? $layers : [] as $layer) {
            if (\is_array($layer) && \is_string($layer['name'] ?? null)) {
                $names[] = $layer['name'];
            }
        }

        return $names;
    }

    /** @param list<string> $declared */
    private static function refersToALayer(string $source, array $declared): bool
    {
        try {
            $selector = LayerSelectorParser::parse($source);
        } catch (InvalidSelectorException) {
            return true;
        }

        return !$selector->isExact() || \in_array($source, $declared, true);
    }
}
