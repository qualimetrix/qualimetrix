<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Support;

use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\DocumentSectionSchemaInterface;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationPipelineInterface;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\Document\AuthoredLayer;
use Qualimetrix\Analysis\Configuration\Document\AuthoredNode;
use Qualimetrix\Analysis\Configuration\Document\DocumentComposer;
use Qualimetrix\Analysis\Configuration\Document\DocumentSchema;
use Qualimetrix\Analysis\Configuration\DocumentRoots;
use Qualimetrix\Analysis\Configuration\Pipeline\ConfigurationPipeline;
use Qualimetrix\Core\Path\AbsolutePath;
use ReflectionProperty;

/**
 * A configuration document built the way the pipeline builds it: every source
 * both as a contribution and as a written layer the engine composes, so a
 * resolver reading either sees the same input.
 *
 * A section no owner declares here is carried unread, as the pipeline carries
 * a root nobody declared; a test of an owner that reads its section passes
 * that owner's declaration, or every declaration a compiled pipeline holds.
 */
final class LayeredDocument
{
    /** @param list<array{source: string, values: array<string, mixed>}> $sources lowest precedence first */
    public static function of(array $sources, AbsolutePath $root, DocumentSectionSchemaInterface ...$sections): ConfigurationDocument
    {
        $layers = [];
        foreach ($sources as $source) {
            $written = array_diff_key($source['values'], array_flip(ConfigSchema::INTERNAL_KEYS));
            if ($written === []) {
                continue;
            }

            $layers[] = new AuthoredLayer(self::origin($source['source']), self::node($written), $source['source'] !== 'cli');
        }

        return new ConfigurationDocument(
            $sources,
            $root,
            DocumentComposer::compose(new DocumentSchema(DocumentRoots::completing(array_values($sections))), $layers),
        );
    }

    /**
     * The sections a pipeline composes with — for a compiled container, every
     * owner's registered declaration, so a test names none of them itself.
     *
     * @return list<DocumentSectionSchemaInterface>
     */
    public static function sectionsOf(ConfigurationPipelineInterface $pipeline): array
    {
        $sections = (new ReflectionProperty(ConfigurationPipeline::class, 'sections'))->getValue($pipeline);
        \assert(\is_array($sections));

        return array_values(array_filter($sections, static fn(mixed $section): bool => $section instanceof DocumentSectionSchemaInterface));
    }

    /** @param array<string, mixed> $flat */
    private static function node(array $flat): AuthoredNode
    {
        $tree = [];
        foreach ($flat as $key => $value) {
            $path = DocumentRoots::pathOf($key);
            if (\count($path) === 1) {
                $tree[$path[0]] = $value;

                continue;
            }

            $section = \is_array($tree[$path[0]] ?? null) ? $tree[$path[0]] : [];
            $section[$path[1]] = $value;
            $tree[$path[0]] = $section;
        }

        return AuthoredNode::fromPlain($tree);
    }

    private static function origin(string $source): ConfigurationOrigin
    {
        return match (true) {
            $source === 'cli' => ConfigurationOrigin::of(ConfigurationSource::CommandLine),
            str_starts_with($source, 'composer') => ConfigurationOrigin::of(ConfigurationSource::ComposerJson, $source),
            str_starts_with($source, 'preset') => ConfigurationOrigin::of(ConfigurationSource::Preset, $source),
            default => ConfigurationOrigin::of(ConfigurationSource::ConfigFile, $source),
        };
    }
}
