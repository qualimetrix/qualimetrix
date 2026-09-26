<?php

declare(strict_types=1);

namespace QmxFindingGate;

/**
 * What one side is handed of a case's inputs: the candidate's own, or — for
 * the reference — the same inputs in the vocabulary the reference knows.
 *
 * Every input the reference reads passes through here and through nothing
 * else, so a new kind of input, and a new way of translating one, is one
 * change to this class rather than a change to how a tree is run.
 */
final class CaseInputTranslation
{
    public function __construct(
        private readonly RenameMaps $maps,
        private readonly bool $reverseInput,
        private readonly string $temporaryDirectory,
        private readonly string $label,
    ) {}

    /** @return list<string> */
    public function arguments(CaseDefinition $case): array
    {
        return $this->reverseInput ? $this->maps->reverseArguments($case->args) : $case->args;
    }

    public function subject(string $subject): string
    {
        return $this->reverseInput ? $this->maps->reverse($subject) : $subject;
    }

    /** @return list<string> */
    public function layerAssignmentSubjects(CaseDefinition $case): array
    {
        return array_map($this->subject(...), $case->layerAssignmentSubjects());
    }

    public function renameChannelsMap(CaseDefinition $case): ?string
    {
        return $case->renameChannelsMap();
    }

    public function baselineSource(CaseDefinition $case): ?string
    {
        return $case->baselineSource();
    }

    /**
     * The reference binary cannot be addressed in a vocabulary it does not know
     * yet, so its configuration is rewritten through the reverse map. When the
     * rewrite changes nothing the case's own file is used as is — and then no
     * artifact can name a temporary path, which is why this is not just an
     * optimisation.
     */
    public function configuration(CaseDefinition $case): string
    {
        if (!$this->reverseInput || $this->maps->isIdentity()) {
            return $case->config;
        }

        $original = Fs::read($case->directory . '/' . $case->config);
        $reversed = $this->maps->reverse($original);

        if ($reversed === $original) {
            return $case->config;
        }

        $path = \sprintf('%s/config-%s-%s-%s', $this->temporaryDirectory, $this->label, $case->id, basename($case->config));
        Fs::write($path, $reversed);

        return $path;
    }
}
