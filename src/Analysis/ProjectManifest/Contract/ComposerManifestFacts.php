<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\ProjectManifest\Contract;

use Qualimetrix\Core\Path\AbsolutePath;

/** Accepted records survive damaged siblings; completeness belongs to each autoload section. */
final readonly class ComposerManifestFacts
{
    /** @param list<ManifestIssue> $issues */
    public function __construct(
        public AbsolutePath $root,
        public ManifestReadState $state,
        public ?string $name,
        public string $vendorDirectory,
        public ComposerAutoloadSection $production,
        public ComposerAutoloadSection $development,
        public array $issues,
    ) {}

    public function source(): string
    {
        return rtrim($this->root->value(), '/') . '/composer.json';
    }

    /** @return list<string> */
    public function productionTargets(): array
    {
        return $this->production->targets();
    }

    /** @return list<string> */
    public function developmentTargets(): array
    {
        return $this->development->targets();
    }

    /** @return array<string, list<string>> Both accepted PSR-4 sections, in declaration order. */
    public function psr4Roots(): array
    {
        $map = [];
        foreach ([$this->production, $this->development] as $section) {
            $prefixes = $section->psr4Roots();
            foreach ($prefixes as $prefix => $paths) {
                $map[$prefix] = array_values(array_unique([...($map[$prefix] ?? []), ...$paths]));
            }
        }

        return $map;
    }

    /** @return list<ManifestIssue> Metadata errors do not damage the selected code universe. */
    public function productionScopeIssues(): array
    {
        return $this->issuesFor(['autoload']);
    }

    /** @return list<ManifestIssue> */
    public function allScopeIssues(): array
    {
        return $this->issuesFor(['autoload', 'autoload-dev']);
    }

    /**
     * @param list<string> $sections
     *
     * @return list<ManifestIssue>
     */
    private function issuesFor(array $sections): array
    {
        return array_values(array_filter($this->issues, static fn(ManifestIssue $issue): bool => $issue->location === []
            || \in_array($issue->location[0], $sections, true)));
    }
}
