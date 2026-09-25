<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Fixtures\Document;

use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedDocument;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\Document\AuthoredLayer;
use Qualimetrix\Analysis\Configuration\Document\DocumentComposer;
use Qualimetrix\Analysis\Configuration\Document\DocumentSchema;
use Qualimetrix\Analysis\Configuration\DocumentRoots;
use Qualimetrix\Analysis\Configuration\Loader\YamlConfigLoader;

/**
 * One configuration file taken the way the pipeline takes it: read as
 * written, composed against the document's roots, and only then refused for
 * what the folded values alone still judge.
 */
final class WrittenFile
{
    /** @throws ConfigurationRefusal */
    public static function compose(string $path): ResolvedDocument
    {
        $loaded = (new YamlConfigLoader())->read($path);
        $document = DocumentComposer::compose(
            new DocumentSchema(DocumentRoots::completing([])),
            [new AuthoredLayer(ConfigurationOrigin::of(ConfigurationSource::ConfigFile, $path), $loaded->authored)],
        );

        if ($loaded->deferredRefusal !== null) {
            throw $loaded->deferredRefusal;
        }

        return $document;
    }
}
