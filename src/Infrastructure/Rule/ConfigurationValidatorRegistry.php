<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Rule;

use Qualimetrix\Analysis\Finding\Contract\ConfigurationValidatorInterface;

/**
 * Registry of configuration-validator classes, the counterpart of
 * {@see RuleRegistry} for the second producer kind.
 *
 * Class names rather than instances, for the same reason: a validator reaches
 * into its capability's run state and only the container can build one, while
 * every fact about it that a caller outside the run needs — its producer, the
 * channels it declares — is static and readable by reflection.
 *
 * It exists so that "which channels are configuration errors" has a second
 * witness beside the compiled universe. **Nothing in production asks it** —
 * the run reads the universe the pass assembled — and that is deliberate, not
 * an oversight: its one consumer is
 * {@see \Qualimetrix\Tests\Analysis\Finding\Integration\ChannelUniverseCoverageTest},
 * which compares the validators the container holds against the channels the
 * universe reports. A check that read only the universe would be reading the
 * same answer production reads, and would agree with it however wrong it was.
 * A reader looking for the production caller should stop here.
 */
final readonly class ConfigurationValidatorRegistry
{
    /** @var list<class-string<ConfigurationValidatorInterface>> */
    private array $validatorClasses;

    /**
     * @param list<class-string<ConfigurationValidatorInterface>> $validatorClasses
     */
    public function __construct(array $validatorClasses)
    {
        $this->validatorClasses = array_values($validatorClasses);
    }

    /**
     * @return list<class-string<ConfigurationValidatorInterface>>
     */
    public function getClasses(): array
    {
        return $this->validatorClasses;
    }
}
