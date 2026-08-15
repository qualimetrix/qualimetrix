<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Parallel\Fixture;

/**
 * Mirrors FileProcessingTask shape — promoted constructor properties with
 * string-typed path semantics. Should be flagged.
 */
final class FileProcessingTaskFixture
{
    public function __construct(
        private readonly string $filePath,
        private readonly ?string $file,
    ) {}
}

/**
 * Same class with the typed VO already adopted — should NOT be flagged.
 */
final class FileProcessingTaskFixtureOk
{
    public function __construct(
        private readonly \Qualimetrix\Core\Path\RelativePath $filePath,
    ) {}
}

/**
 * Non-promoted constructor parameter with a banned name. The promoted-property
 * rule must NOT fire here — flags === 0. (Phase 0 deliberately exempts plain
 * parameters; the long tail of legitimate string-typed `$path` parameters in
 * the config loader/CLI layer would otherwise drown the signal.)
 */
final class NonPromotedFixture
{
    public function __construct(string $filePath)
    {
        $this->something = $filePath;
    }

    public string $something = '';
}

/**
 * Promoted constructor parameter with a non-banned name. Must NOT be flagged.
 */
final class NotABannedNameFixture
{
    public function __construct(
        private readonly string $configKey,
    ) {}
}
