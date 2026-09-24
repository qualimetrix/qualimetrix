<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Contract\Discovery;

use Qualimetrix\Analysis\Run\Contract\Pipeline\AnalysisFailureKind;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Core\Path\PathFactory;
use Qualimetrix\Core\Path\RelativePath;

/**
 * One entry discovery refused to hand on, and why.
 *
 * "Entry" rather than "file": a directory symlink and an unreadable directory
 * are the cases that cost the most code, and calling either a file is what let
 * a directory be counted as an analyzed file in the first place.
 */
final readonly class SkippedEntry
{
    public function __construct(
        public AbsolutePath $path,
        public AnalysisFailureKind $reason,
        public string $detail,
    ) {}

    /**
     * The name the reader was pointed at, not the name it resolves to.
     *
     * Canonicalizing a symbolic link reports its target — a path that may sit
     * outside the project and that nothing in the tree is called — while this
     * value is the key baselines and suppressions are indexed by. So the last
     * segment is never handed to a conversion that resolves what it is given:
     * only the containing directory is resolved, and the entry's own name is
     * appended to the answer afterwards. Both branches obey that, which is the
     * part that used to hold for the in-root branch alone: the out-of-root
     * fallback canonicalizes its whole argument, so it is given the parent.
     */
    public function relativeTo(AbsolutePath $projectRoot): RelativePath
    {
        $value = $this->path->value();
        $name = basename($value);
        $parentValue = \dirname($value);
        $parentReal = realpath($parentValue);
        $parent = $parentReal === false ? $parentValue : $parentReal;

        $canonicalRoot = realpath($projectRoot->value());
        $base = $canonicalRoot === false ? $projectRoot : AbsolutePath::fromString($canonicalRoot);

        if ($name === '') {
            // `basename('/')`: a path that is its own parent has no name to
            // protect. AbsolutePath normalizes a trailing slash away, so this
            // is the only spelling that reaches here.
            return PathFactory::bestEffortRelative($value, $base);
        }

        $inRoot = PathFactory::tryProjectRelative($parent . '/' . $name, $base);
        if ($inRoot !== null) {
            return $inRoot;
        }

        $entryName = RelativePath::fromString($name);

        // The filesystem root relativizes to nothing structural, so appending
        // to it would invent a segment the fallback made up.
        return $parent === '/'
            ? $entryName
            : PathFactory::bestEffortRelative($parent, $base)->join($entryName);
    }
}
