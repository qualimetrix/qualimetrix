<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Integration\EditorSchema;

use Qualimetrix\Analysis\Configuration\ConfigurationRoot;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\Document\AuthoredLayer;
use Qualimetrix\Analysis\Configuration\Document\DocumentComposer;
use Qualimetrix\Analysis\Configuration\Document\DocumentSchema;
use Qualimetrix\Analysis\Configuration\EditorSchema\EditorSchemaProjection;
use Qualimetrix\Analysis\Configuration\Loader\YamlConfigLoader;
use Qualimetrix\Tests\Analysis\Configuration\Support\LayeredDocument;
use RuntimeException;

/**
 * One configuration file read as the product reads it — the YAML loader, then
 * the document engine against every registered section — and the editor
 * schema projected from that same document schema.
 */
final class EngineReading
{
    private static ?DocumentSchema $document = null;

    /** @var array<string, mixed>|null */
    private static ?array $schema = null;

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        return self::$schema ??= EditorSchemaProjection::project(self::document()->root());
    }

    /** @return list<string> the canonical keys the document root declares */
    public static function rootKeys(): array
    {
        return array_map(strval(...), array_keys(self::document()->root()->map->keys->fields()));
    }

    /**
     * The engine's refusal of the text as one config file, or null when it accepts it.
     */
    public static function refusal(string $yaml): ?ConfigurationRefusal
    {
        $path = sys_get_temp_dir() . '/qmx-editor-schema-' . bin2hex(random_bytes(8)) . '.yaml';
        if (file_put_contents($path, $yaml) === false) {
            throw new RuntimeException('Cannot write ' . $path);
        }

        try {
            $loaded = (new YamlConfigLoader())->read($path, 'qmx.yaml');
            DocumentComposer::compose(self::document(), [
                new AuthoredLayer(ConfigurationOrigin::of(ConfigurationSource::ConfigFile, 'qmx.yaml'), $loaded->authored),
            ]);
        } catch (ConfigurationRefusal $refusal) {
            return $refusal;
        } finally {
            unlink($path);
        }

        return null;
    }

    private static function document(): DocumentSchema
    {
        return self::$document ??= new DocumentSchema([...ConfigurationRoot::cases(), ...LayeredDocument::standaloneSections()]);
    }
}
