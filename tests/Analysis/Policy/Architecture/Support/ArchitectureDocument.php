<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Support;

use Qualimetrix\Analysis\Configuration\Contract\Document\ResolvedDocument;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\Document\AuthoredLayer;
use Qualimetrix\Analysis\Configuration\Document\AuthoredNode;
use Qualimetrix\Analysis\Configuration\Document\DocumentComposer;
use Qualimetrix\Analysis\Configuration\Document\DocumentSchema;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\ArchitectureSection;
use Qualimetrix\Analysis\Policy\Architecture\Configuration\SectionSpot;

/**
 * An `architecture:` section composed the way the configuration engine
 * composes it for a run: recognised, shaped and merged by
 * {@see ArchitectureSection}, so a test reads exactly what the factory and its
 * validators read in production.
 */
final class ArchitectureDocument
{
    public const string FILE = '/project/qmx.yaml';

    /** One configuration file writing `$section` under `architecture:`. */
    public static function file(mixed $section): ResolvedDocument
    {
        return self::compose(self::fileLayer($section));
    }

    /**
     * Sections written by successive layers, lowest precedence first: every
     * one but the last is a preset, the last is the configuration file.
     */
    public static function stacked(mixed ...$sections): ResolvedDocument
    {
        $sections = array_values($sections);
        $layers = [];
        foreach ($sections as $index => $section) {
            $layers[] = $index === \count($sections) - 1 ? self::fileLayer($section) : self::presetLayer($section, 'preset-' . $index);
        }

        return self::compose(...$layers);
    }

    public static function compose(AuthoredLayer ...$layers): ResolvedDocument
    {
        return DocumentComposer::compose(new DocumentSchema([new ArchitectureSection()]), array_values($layers));
    }

    public static function fileLayer(mixed $section, string $path = self::FILE): AuthoredLayer
    {
        return new AuthoredLayer(
            ConfigurationOrigin::of(ConfigurationSource::ConfigFile, $path),
            AuthoredNode::fromPlain(['architecture' => $section]),
        );
    }

    public static function presetLayer(mixed $section, string $name = 'strict'): AuthoredLayer
    {
        return new AuthoredLayer(
            ConfigurationOrigin::of(ConfigurationSource::Preset, $name),
            AuthoredNode::fromPlain(['architecture' => $section]),
        );
    }

    /** The spot `architecture.<path…>` of one configuration file writing `$section`. */
    public static function spot(mixed $section, string|int ...$path): SectionSpot
    {
        $spot = SectionSpot::section('architecture', self::file($section)->get('architecture'));
        foreach ($path as $segment) {
            $spot = $spot->child($segment);
        }

        return $spot;
    }

    /** `architecture.layers`, as one file writes it. */
    public static function layers(mixed $layers): SectionSpot
    {
        return self::spot(['layers' => $layers], 'layers');
    }

    /**
     * `architecture.allow`, as one file writes it beside a layer for every
     * source it names, so the document admits each source name.
     */
    public static function allow(mixed $allow): SectionSpot
    {
        return self::spot(self::withSourceLayers($allow), 'allow');
    }

    /**
     * An `architecture:` section writing `$allow` and declaring a layer named
     * by every source key of it.
     *
     * @return array<string, mixed>
     */
    public static function withSourceLayers(mixed $allow): array
    {
        $layers = [];
        foreach (\is_array($allow) ? array_keys($allow) : [] as $source) {
            $layers[] = ['name' => (string) $source, 'patterns' => ['App\\' . $source]];
        }

        return $layers === [] ? ['allow' => $allow] : ['layers' => $layers, 'allow' => $allow];
    }

    /** `architecture.allow.<source>[<index>]` of a file writing one long-form target. */
    public static function allowTarget(mixed $target, string $source = 'app'): SectionSpot
    {
        return self::spot(self::withSourceLayers([$source => [$target]]), 'allow', $source, 0);
    }

    /** `architecture.allow.app[0].relations`, as one file writes it. */
    public static function relations(mixed $relations): SectionSpot
    {
        return self::allowTarget(['target' => 'domain', 'relations' => $relations])->child('relations');
    }
}
