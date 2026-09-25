<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Document;

use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedDocument;
use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedMap;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;

/**
 * Composes the ordered layers into one resolved document, in a fixed order of
 * phases: (1) each layer is recognised and shaped on its own; (2) the layers
 * merge by each node's declared policy; (3) names drawn from the merged
 * document are judged; (4) the result keeps each leaf's winning layer and
 * each merged node's contributors.
 *
 * Every layer passes phase 1 before any merge, so a malformed value is
 * refused even when a higher layer overrides it.
 */
final class DocumentComposer
{
    /**
     * @param list<AuthoredLayer> $layers lowest precedence first
     *
     * @throws ConfigurationRefusal
     */
    public static function compose(DocumentSchema $schema, array $layers): ResolvedDocument
    {
        $root = $schema->root();
        $read = [];
        $pending = [];

        foreach ($layers as $layer) {
            $reading = new LayerReading($schema->admitsUndeclaredRoots);
            $read[] = $reading->readRoot($root, $layer);
            $pending = [...$pending, ...$reading->pendingNames()];
        }

        $merge = new LayerMerge();
        $merged = null;
        foreach ($read as $value) {
            $merged = $merge->merge($root, $merged, $value);
        }

        $document = new ResolvedDocument(
            $merged instanceof ResolvedMap ? $merged->entries() : [],
            $merge->diagnostics(),
        );

        NameRecognition::judge($document, $pending);

        return $document;
    }
}
