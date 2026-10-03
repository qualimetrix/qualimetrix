<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Policy\Inline\Directive;

use Closure;
use Qualimetrix\Analysis\Finding\Contract\ChannelDeclarationRegistryInterface;
use Qualimetrix\Analysis\Finding\Contract\ChannelIdentityInterface;
use Qualimetrix\Analysis\Finding\Contract\Threshold\ThresholdOverride;
use Qualimetrix\Analysis\Policy\Inline\Contract\Directive\DirectiveSite;
use Qualimetrix\Analysis\Policy\Inline\Contract\Suppression\Suppression;
use Qualimetrix\Analysis\Policy\Inline\Contract\Threshold\ThresholdDiagnostic;
use Qualimetrix\Core\Path\RelativePath;

/** The same classification for finding publication and directive accounting. */
final readonly class RefusedDirectives
{
    private DirectiveAddressability $addressability;

    public function __construct(ChannelIdentityInterface&ChannelDeclarationRegistryInterface $identity)
    {
        $this->addressability = new DirectiveAddressability($identity);
    }

    /**
     * @param array<string, list<Suppression>> $suppressionsByFile
     * @param array<string, list<ThresholdOverride>> $overridesByFile
     * @param array<string, list<ThresholdDiagnostic>> $diagnosticsByFile
     *
     * @return list<RefusedDirective>
     */
    public function all(array $suppressionsByFile, array $overridesByFile, array $diagnosticsByFile): array
    {
        return [
            ...$this->classifyByFile($suppressionsByFile, $this->suppression(...)),
            ...$this->classifyByFile($overridesByFile, $this->threshold(...)),
            ...$this->classifyByFile($diagnosticsByFile, $this->diagnostic(...)),
        ];
    }

    /**
     * @template T
     *
     * @param array<string, list<T>> $byFile
     * @param Closure(RelativePath, T): ?RefusedDirective $classify
     *
     * @return list<RefusedDirective>
     */
    private function classifyByFile(array $byFile, Closure $classify): array
    {
        $refused = [];
        foreach ($byFile as $file => $directives) {
            foreach ($directives as $directive) {
                $refusal = $classify(RelativePath::fromString($file), $directive);
                if ($refusal !== null) {
                    $refused[] = $refusal;
                }
            }
        }

        return $refused;
    }

    public function suppression(RelativePath $file, Suppression $suppression): ?RefusedDirective
    {
        $message = $this->addressability->problemWithSuppression($suppression);
        if ($message === null) {
            return null;
        }

        return new RefusedDirective(
            new DirectiveSite($file, $suppression->line, $suppression->form(), $suppression->rule, $suppression->position),
            DirectiveRefusalChannel::Unresolved,
            $message,
        );
    }

    public function threshold(RelativePath $file, ThresholdOverride $override): ?RefusedDirective
    {
        $rejection = $this->addressability->problemWithThreshold($override);
        if ($rejection === null) {
            return null;
        }

        return new RefusedDirective(
            new DirectiveSite($file, $override->line, 'threshold', $override->rulePattern, null),
            $rejection->ruleExistsButCannotBeRetuned ? DirectiveRefusalChannel::Unsupported : DirectiveRefusalChannel::Unresolved,
            $rejection->message,
            addressedProducer: $rejection->ruleExistsButCannotBeRetuned
                ? $this->addressability->addressedProducerOf($override->rulePattern)
                : null,
        );
    }

    public function diagnostic(RelativePath $file, ThresholdDiagnostic $diagnostic): RefusedDirective
    {
        return new RefusedDirective(
            new DirectiveSite($file, $diagnostic->line, 'threshold', $diagnostic->rulePattern, $diagnostic->position),
            DirectiveRefusalChannel::Invalid,
            $this->addressability->describeDiagnostic($diagnostic),
            $diagnostic->hint,
            $this->addressability->addressedProducerOf($diagnostic->rulePattern),
        );
    }
}
