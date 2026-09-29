<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\ProjectManifest\Contract;

use Qualimetrix\Core\Path\AbsolutePath;

/** Accepted records survive damaged siblings; completeness belongs to each autoload section. */
final readonly class ComposerManifestFacts
{
    /**
     * @param array{'psr-4'?: array<string, list<string>>, 'psr-0'?: array<string, list<string>>, classmap?: list<string>, files?: list<string>} $production
     * @param array{'psr-4'?: array<string, list<string>>, 'psr-0'?: array<string, list<string>>, classmap?: list<string>, files?: list<string>} $development
     * @param list<ManifestIssue> $issues
     */
    public function __construct(
        public AbsolutePath $root,
        public ManifestReadState $state,
        public ?string $name,
        public string $vendorDirectory,
        public array $production,
        public array $development,
        public bool $productionComplete,
        public bool $developmentComplete,
        public array $issues,
    ) {}

    public function source(): string
    {
        return rtrim($this->root->value(), '/') . '/composer.json';
    }

    /** @return list<string> */
    public function productionTargets(): array
    {
        return self::targets($this->production);
    }

    /** @return list<string> */
    public function developmentTargets(): array
    {
        return self::targets($this->development);
    }

    /** @return array<string, list<string>> Both accepted PSR-4 sections, in declaration order. */
    public function psr4Roots(): array
    {
        $map = [];
        foreach ([$this->production, $this->development] as $section) {
            /** @var array<string, list<string>> $prefixes */
            $prefixes = $section['psr-4'] ?? [];
            foreach ($prefixes as $prefix => $paths) {
                $map[$prefix] = array_values(array_unique([...($map[$prefix] ?? []), ...$paths]));
            }
        }

        return $map;
    }

    /** @return list<ManifestIssue> Metadata errors do not damage the selected code universe. */
    public function scopeIssues(bool $includeDevelopment): array
    {
        return array_values(array_filter($this->issues, static fn(ManifestIssue $issue): bool => $issue->location === []
            || $issue->location[0] === 'autoload'
            || ($includeDevelopment && $issue->location[0] === 'autoload-dev')));
    }

    /**
     * @param array{'psr-4'?: array<string, list<string>>, 'psr-0'?: array<string, list<string>>, classmap?: list<string>, files?: list<string>} $section
     *
     * @return list<string>
     */
    private static function targets(array $section): array
    {
        $paths = [];
        foreach (['psr-4', 'psr-0'] as $kind) {
            foreach ($section[$kind] ?? [] as $prefixPaths) {
                foreach ($prefixPaths as $path) {
                    $paths[] = $path;
                }
            }
        }

        return array_values(array_unique([...$paths, ...($section['classmap'] ?? []), ...($section['files'] ?? [])]));
    }
}
