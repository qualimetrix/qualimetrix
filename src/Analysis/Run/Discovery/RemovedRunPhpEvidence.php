<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Discovery;

use Closure;
use Qualimetrix\Core\Path\AbsolutePath;

/**
 * What a real authored removal of a selected run entry took out of the run:
 * the selector evidence, from metadata only, and the namespaces its PHP files
 * declare, which go to the survey.
 *
 * While the survey is open the descent visits every entry, because the
 * selector verdict needs one witness while the namespaces need all of them.
 * Once it is closed the descent stops at the first PHP file, as the verdict
 * needs no more.
 */
final readonly class RemovedRunPhpEvidence
{
    public function __construct(private EntryInspectorInterface $inspector, private RemovedNamespaceSurvey $survey) {}

    /** @return string|null `php-file`, `unlistable` or null */
    public function search(AbsolutePath $path, EntryKind $kind): ?string
    {
        return $this->descend($path, $kind, static fn(?string $evidence): bool => $evidence === 'php-file');
    }

    /** Surveys an entry whose removal judges no selector: only its namespaces are wanted. */
    public function surveyOnly(AbsolutePath $path, EntryKind $kind): void
    {
        if ($this->survey->isOpen()) {
            $this->descend($path, $kind, static fn(): bool => true);
        }
    }

    /** @param Closure(?string): bool $settled whether the evidence found so far ends a descent the survey no longer needs */
    private function descend(AbsolutePath $path, EntryKind $kind, Closure $settled): ?string
    {
        return match ($kind) {
            EntryKind::RegularFile => $this->file($path),
            EntryKind::Directory, EntryKind::DirectoryLink => $this->directory($path, $settled),
            EntryKind::StatFailed => $this->unlistable(),
            default => $this->nonRegular($path),
        };
    }

    private function file(AbsolutePath $path): ?string
    {
        if (!str_ends_with($path->value(), '.php')) {
            return null;
        }
        $this->survey->read($path);

        return 'php-file';
    }

    /** @param Closure(?string): bool $settled */
    private function directory(AbsolutePath $path, Closure $settled): ?string
    {
        $children = $this->inspector->list($path->value());
        if ($children === null) {
            return $this->unlistable();
        }
        $evidence = null;
        foreach ($children as $name) {
            if (!$this->survey->isOpen() && $settled($evidence)) {
                break;
            }
            $child = AbsolutePath::fromString($path->value() . '/' . $name);
            $this->survey->visit();
            $childKind = $this->inspector->inspect($child->value());
            if ($childKind === EntryKind::DirectoryLink) {
                // The walk records a traversed directory link as a skipped entry; its code is neither analysed nor read here.
                $this->survey->close();
            } else {
                $evidence = self::stronger($evidence, $this->descend($child, $childKind, $settled));
            }
        }

        return $evidence;
    }

    /** The walk records a non-regular PHP entry as a skipped entry and ignores any other, so only the former hides namespaces. */
    private function nonRegular(AbsolutePath $path): null
    {
        if (str_ends_with($path->value(), '.php')) {
            $this->survey->close();
        }

        return null;
    }

    private function unlistable(): string
    {
        $this->survey->close();

        return 'unlistable';
    }

    private static function stronger(?string $current, ?string $found): ?string
    {
        foreach (['php-file', 'unlistable'] as $evidence) {
            if ($current === $evidence || $found === $evidence) {
                return $evidence;
            }
        }

        return null;
    }
}
