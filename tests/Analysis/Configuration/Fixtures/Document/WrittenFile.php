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
use Qualimetrix\Analysis\Configuration\Loader\YamlConfigLoader;
use Qualimetrix\Tests\Analysis\Configuration\Support\LayeredDocument;

/**
 * One configuration file taken the way the pipeline takes it: read as
 * written and composed against the declarations of its roots.
 */
final class WrittenFile
{
    /** @throws ConfigurationRefusal */
    public static function compose(string $path): ResolvedDocument
    {
        $loaded = (new YamlConfigLoader())->read($path, $path);
        $document = DocumentComposer::compose(
            new DocumentSchema([...\Qualimetrix\Analysis\Configuration\ConfigurationRoot::cases(), ...LayeredDocument::standaloneSections()]),
            [new AuthoredLayer(ConfigurationOrigin::of(ConfigurationSource::ConfigFile, $path), $loaded->authored)],
        );

        return $document;
    }
}
