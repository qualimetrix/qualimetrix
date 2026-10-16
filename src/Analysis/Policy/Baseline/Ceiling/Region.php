<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Baseline\Ceiling;

use Qualimetrix\Core\Path\RelativePath;

/** The PHP files whose membership can affect one baseline identity. */
final readonly class Region
{
    /** @param list<RelativePath> $roots */
    private function __construct(
        public string $kind,
        public ?RelativePath $file = null,
        public array $roots = [],
    ) {}

    public static function whole(): self
    {
        return new self('whole');
    }

    public static function file(RelativePath $file): self
    {
        return new self('file', $file);
    }

    /** @param list<RelativePath> $roots */
    public static function namespace(array $roots): self
    {
        return new self('namespace', roots: $roots);
    }

    public static function empty(): self
    {
        return new self('empty');
    }

    public function contains(RelativePath $file): bool
    {
        return match ($this->kind) {
            'whole' => true,
            'file' => $this->file?->value() === $file->value(),
            'namespace' => array_any($this->roots, static fn(RelativePath $root): bool => $file->value() === $root->value()
                || str_starts_with($file->value(), rtrim($root->value(), '/') . '/')),
            default => false,
        };
    }
}
