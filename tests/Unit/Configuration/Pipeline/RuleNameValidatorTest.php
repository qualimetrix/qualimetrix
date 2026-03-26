<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Configuration\Pipeline;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Qualimetrix\Configuration\KnownRuleNamesProviderInterface;
use Qualimetrix\Configuration\Pipeline\RuleNameValidator;

#[CoversClass(RuleNameValidator::class)]
final class RuleNameValidatorTest extends TestCase
{
    #[Test]
    public function exactMatch_noWarning(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        RuleNameValidator::warnAboutUnknownRuleNames(
            ['rules' => ['complexity.cyclomatic' => ['method' => ['warning' => 10]]]],
            'test.yaml',
            $this->createProvider(['complexity.cyclomatic']),
            $logger,
        );
    }

    #[Test]
    public function forwardPrefixMatch_noWarning(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        RuleNameValidator::warnAboutUnknownRuleNames(
            ['rules' => ['complexity' => ['cyclomatic' => ['method' => ['warning' => 10]]]]],
            'test.yaml',
            $this->createProvider(['complexity.cyclomatic', 'complexity.cognitive']),
            $logger,
        );
    }

    #[Test]
    public function reversePrefixMatch_noWarning(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        RuleNameValidator::warnAboutUnknownRuleNames(
            ['rules' => ['complexity.cyclomatic.method' => ['warning' => 10]]],
            'test.yaml',
            $this->createProvider(['complexity.cyclomatic']),
            $logger,
        );
    }

    #[Test]
    public function unknownRuleName_warningEmitted(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                self::stringContains('Unknown rule name'),
                self::equalTo(['rule' => 'nonexistent.rule', 'source' => 'preset:strict']),
            );

        RuleNameValidator::warnAboutUnknownRuleNames(
            ['rules' => ['nonexistent.rule' => ['warning' => 10]]],
            'preset:strict',
            $this->createProvider(['complexity.cyclomatic']),
            $logger,
        );
    }

    #[Test]
    public function emptyRulesSection_noWarning(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        RuleNameValidator::warnAboutUnknownRuleNames(
            ['rules' => []],
            'test.yaml',
            $this->createProvider(['complexity.cyclomatic']),
            $logger,
        );
    }

    #[Test]
    public function noRulesSection_noWarning(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        RuleNameValidator::warnAboutUnknownRuleNames(
            ['format' => 'json'],
            'test.yaml',
            $this->createProvider(['complexity.cyclomatic']),
            $logger,
        );
    }

    #[Test]
    public function multipleUnknownNames_multipleWarnings(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::exactly(2))->method('warning');

        RuleNameValidator::warnAboutUnknownRuleNames(
            ['rules' => [
                'nonexistent.one' => ['warning' => 5],
                'nonexistent.two' => ['warning' => 10],
            ]],
            'test.yaml',
            $this->createProvider(['complexity.cyclomatic']),
            $logger,
        );
    }

    /**
     * @param list<string> $names
     */
    private function createProvider(array $names): KnownRuleNamesProviderInterface
    {
        $provider = $this->createStub(KnownRuleNamesProviderInterface::class);
        $provider->method('getKnownRuleNames')->willReturn($names);

        return $provider;
    }
}
