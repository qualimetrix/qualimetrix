<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Document;

use Qualimetrix\Analysis\Configuration\Contract\Document\ConfigurationDiagnostic;
use Qualimetrix\Analysis\Configuration\Contract\Document\Provenance;
use Qualimetrix\Analysis\Configuration\Document\Resolved\ResolvedList;
use SplObjectStorage;

/** The live authored diagnostic for a list whose empty replacement still stands. */
final class EmptyListOverrides
{
    /**
     * @var SplObjectStorage<ResolvedList, array{Provenance, Provenance, string}>
     */
    private SplObjectStorage $replacements;

    public function __construct()
    {
        $this->replacements = new SplObjectStorage();
    }

    public function considerReplacement(ResolvedList $lower, ResolvedList $upper, ResolvedList $result, ?string $notice): void
    {
        $replaced = isset($this->replacements[$lower]) ? $this->replacements[$lower] : null;
        unset($this->replacements[$lower]);

        if ($notice === null || $upper->items() !== []) {
            return;
        }

        // A later empty replacement still names the writer of the original list.
        $lowerWriter = $lower->items() !== []
            ? $lower->contributors()[\count($lower->contributors()) - 1]
            : $replaced[1] ?? null;

        if ($lowerWriter !== null) {
            $this->replacements[$result] = [$upper->contributors()[0], $lowerWriter, $notice];
        }
    }

    /** @return list<ConfigurationDiagnostic> */
    public function diagnostics(): array
    {
        $diagnostics = [];
        foreach ($this->replacements as $list) {
            [$upperWriter, $lowerWriter, $notice] = $this->replacements[$list];
            $diagnostics[] = new ConfigurationDiagnostic(
                \sprintf(
                    '%s is written empty in %s and replaces the list %s wrote. %s',
                    self::named($upperWriter),
                    $upperWriter->origin->describe(),
                    $lowerWriter->origin->describe(),
                    $notice,
                ),
                [$lowerWriter, $upperWriter],
            );
        }

        return $diagnostics;
    }

    private static function named(Provenance $writer): string
    {
        return $writer->path === null ? 'The list' : \sprintf('"%s"', $writer->displayPath());
    }
}
