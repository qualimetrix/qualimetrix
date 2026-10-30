<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Finding\SuppressionBinding;

use Qualimetrix\Analysis\Configuration\Contract\Document\ConfiguredValueWriters;
use Qualimetrix\Analysis\Finding\Contract\Population\GateInput;
use Qualimetrix\Analysis\Finding\Exclusion\ConfiguredSuppression;
use Qualimetrix\Core\Path\RelativePath;
use Qualimetrix\Core\Pattern\NamespacePattern;
use Qualimetrix\Core\Pattern\PathPattern;

/**
 * The questions {@see UnboundSuppressionAudit} asks of each configured value
 * against one run: whether the run can reach what the value names, whether
 * the value binds, and whether the project answers for it.
 *
 * **The project does not answer for a value it can neither edit nor take
 * away** ({@see installedOnly()}): one every writer of which is a file an
 * installed Composer package provides, which no later layer can remove, and
 * which would be reported. A value that binds is judged exactly as it would be
 * without the question, so it is never withheld for its writers; nor is a
 * value of unknown writers. A global value accumulates across layers, and so
 * does each `suppress_namespace_channels` entry: a later layer can merge a key
 * but not drop one, and an empty entry is refused at load. A per-rule
 * `suppress_paths` or `suppress_namespaces` list is replaced whole, `[]`
 * included, so the project can always take it away.
 *
 * @phpstan-type FileUniverse array{analysed: list<RelativePath>, failedFiles: list<RelativePath>, failedDirectories: list<RelativePath>, generatedExcluded: list<RelativePath>}
 * @phpstan-type ConfiguredValue array{channel: string, rule: ?string, option: string, pattern: PathPattern|NamespacePattern, writers: ?ConfiguredValueWriters}
 */
final readonly class ConfiguredValueJudge
{
    /**
     * @param FileUniverse $files the files the run analysed and the entries it left unanalysed
     * @param ?list<string> $declaredNamespaces every namespace the run declared, or `null` if it built no tree
     */
    public function __construct(
        private array $files,
        private ?array $declaredNamespaces,
        private ValueScopeJudgement $scope,
    ) {}

    /** @param ConfiguredValue $value */
    public function judges(array $value): bool
    {
        return $this->reaches($value) && !$this->installedOnly($value);
    }

    /**
     * Whether this run can judge the value at all: its shape reaches the place
     * the value names, no unanalysed entry may hold what an unbound path value
     * names, and — for a namespace — the run built the whole universe it is
     * judged against.
     *
     * @param ConfiguredValue $value
     */
    public function reaches(array $value): bool
    {
        $pattern = $value['pattern'];

        if ($pattern instanceof PathPattern) {
            return $this->scope->judgesPathValue($pattern)
                && (!PathBinding::unanalysedMayHold($pattern, $this->files) || PathBinding::binds($pattern, $this->files['analysed']));
        }

        if (!$this->namespaceUniverseComplete()) {
            return false;
        }

        // Removed code can only add namespaces, so a value the analysed tree
        // already binds stays bound whatever that code declares.
        return $this->scope->judgesNamespaceValue($pattern)
            || ($this->scope->withholdsOnlyForRemovedCode($pattern) && $this->bindsNamespace($pattern));
    }

    /** @param ConfiguredValue $value */
    public function binds(array $value): bool
    {
        $pattern = $value['pattern'];

        return $pattern instanceof PathPattern
            ? PathBinding::binds($pattern, $this->files['analysed'])
            : $this->bindsNamespace($pattern);
    }

    /**
     * Whether only the writers keep a value from being reported: it binds
     * nothing, no later layer can take it away, and every one of its writers
     * is established as installed.
     *
     * @param ConfiguredValue $value
     */
    public function installedOnly(array $value): bool
    {
        $writers = $value['writers'];

        return $writers !== null
            && ($value['rule'] === null || str_starts_with($value['option'], ConfiguredSuppression::NAMESPACE_CHANNELS . '.'))
            && $writers->writtenOnlyBy($this->scope->installedConfigurationSources())
            && !$this->binds($value);
    }

    /**
     * Whether removed code is the only reason a namespace value is not judged:
     * it would be, had that code declared nothing. One the analysed tree
     * already binds is judged and bound, and one only installed files wrote
     * is not named: the project cannot act on the advice that would judge it.
     *
     * @param ConfiguredValue $value
     */
    public function withheldOnlyForRemovedCode(array $value): bool
    {
        $pattern = $value['pattern'];

        return $pattern instanceof NamespacePattern
            && $this->namespaceUniverseComplete()
            && $this->scope->withholdsOnlyForRemovedCode($pattern)
            && !$this->bindsNamespace($pattern)
            && !$this->installedOnly($value);
    }

    /**
     * The gate inputs of a value's population, one per declared gate of its
     * channel, the writers question last: a value out of reach is a gap in
     * the data, an installed one a boundary of what the project can judge.
     *
     * @param ConfiguredValue $value
     *
     * @return list<GateInput>
     */
    public function populationInputs(array $value): array
    {
        $reach = GateInput::context($value['pattern'] instanceof PathPattern ? 'suppressionPathJudged' : 'suppressionNamespaceJudged', $this->reaches($value));
        $writtenHere = GateInput::context('configuredValueWrittenHere', !$this->installedOnly($value));

        return $value['rule'] === null ? [$reach, $writtenHere] : [$reach, $reach, $writtenHere];
    }

    private function namespaceUniverseComplete(): bool
    {
        return $this->declaredNamespaces !== null && $this->files['failedFiles'] === [] && $this->files['failedDirectories'] === [];
    }

    private function bindsNamespace(NamespacePattern $pattern): bool
    {
        return array_any($this->declaredNamespaces ?? [], static fn(string $namespace): bool => $pattern->matches($namespace));
    }
}
