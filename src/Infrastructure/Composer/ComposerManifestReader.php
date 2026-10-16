<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Composer;

use Qualimetrix\Analysis\ProjectManifest\Contract\ComposerAutoloadSection;
use Qualimetrix\Analysis\ProjectManifest\Contract\ComposerManifestDecoder;
use Qualimetrix\Analysis\ProjectManifest\Contract\ComposerManifestFacts;
use Qualimetrix\Analysis\ProjectManifest\Contract\ComposerManifestReaderInterface;
use Qualimetrix\Analysis\ProjectManifest\Contract\ManifestIssue;
use Qualimetrix\Analysis\ProjectManifest\Contract\ManifestIssueKind;
use Qualimetrix\Analysis\ProjectManifest\Contract\ManifestReadState;
use Qualimetrix\Analysis\ProjectManifest\Contract\ManifestSnapshotControlInterface;
use Qualimetrix\Core\Path\AbsolutePath;

/** Each canonical project root is read once, including absence and failed reads. */
final class ComposerManifestReader implements ComposerManifestReaderInterface, ManifestSnapshotControlInterface
{
    /** @var array<string, ComposerManifestFacts> */
    private array $snapshots = [];

    public function __construct(private readonly ComposerManifestDecoder $decoder = new ComposerManifestDecoder()) {}

    public function beginInvocation(): void
    {
        $this->snapshots = [];
    }

    public function read(AbsolutePath $root): ComposerManifestFacts
    {
        $canonical = realpath($root->value());
        $root = $canonical === false ? $root : AbsolutePath::fromString($canonical);
        $key = $root->value();
        if (isset($this->snapshots[$key])) {
            return $this->snapshots[$key];
        }

        $source = rtrim($key, '/') . '/composer.json';
        if (!file_exists($source) && !is_link($source)) {
            return $this->snapshots[$key] = $this->unavailable($root, ManifestReadState::Absent, ManifestIssueKind::Absent, 'No composer.json exists at this root.');
        }

        $bytes = is_file($source) ? @file_get_contents($source) : false;
        if ($bytes === false) {
            return $this->snapshots[$key] = $this->unavailable($root, ManifestReadState::Unreadable, ManifestIssueKind::Unreadable, 'The manifest exists but could not be opened as a file.');
        }

        $facts = $this->decoder->decode($root, $bytes);

        return $this->snapshots[$key] = new ComposerManifestFacts(
            $root,
            $facts->state,
            $facts->name,
            $facts->vendorDirectory,
            $this->expandClassmap($facts->production, $key),
            $this->expandClassmap($facts->development, $key),
            $facts->issues,
        );
    }

    public function observedIssues(): array
    {
        $issues = [];
        foreach ($this->snapshots as $snapshot) {
            array_push($issues, ...$snapshot->issues);
        }

        return $issues;
    }

    private function unavailable(AbsolutePath $root, ManifestReadState $state, ManifestIssueKind $kind, string $detail): ComposerManifestFacts
    {
        return new ComposerManifestFacts($root, $state, null, 'vendor', new ComposerAutoloadSection([], false), new ComposerAutoloadSection([], false), [
            new ManifestIssue($kind, rtrim($root->value(), '/') . '/composer.json', [], $detail),
        ]);
    }

    private function expandClassmap(ComposerAutoloadSection $section, string $root): ComposerAutoloadSection
    {
        if (!isset($section->mappings['classmap'])) {
            return $section;
        }

        $expanded = [];
        foreach ($section->mappings['classmap'] as $path) {
            $matches = $this->classmapMatches($path, $root);
            if ($matches === []) {
                $expanded[] = $path;
                continue;
            }
            foreach ($matches as $match) {
                $expanded[] = $this->relativeMatch($match, $root);
            }
        }
        $mappings = $section->mappings;
        $mappings['classmap'] = array_values(array_unique($expanded));

        return new ComposerAutoloadSection($mappings, $section->complete);
    }

    /** @return list<string> */
    private function classmapMatches(string $path, string $root): array
    {
        if (!str_contains($path, '*')) {
            return [];
        }
        $absolute = str_starts_with($path, '/') ? $path : $root . '/' . $path;
        $matches = glob($absolute, \GLOB_ONLYDIR);
        if ($matches === false) {
            return [];
        }
        sort($matches);

        return $matches;
    }

    private function relativeMatch(string $match, string $root): string
    {
        return str_starts_with($match, $root . '/') ? substr($match, \strlen($root) + 1) : $match;
    }
}
