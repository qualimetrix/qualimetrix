<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Unit\Configuration\Pipeline;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Configuration\Exception\ConfigLoadException;
use Qualimetrix\Configuration\KnownRuleNamesProviderInterface;
use Qualimetrix\Configuration\Pipeline\RuleNameValidator;

#[CoversClass(RuleNameValidator::class)]
final class RuleNameValidatorTest extends TestCase
{
    #[Test]
    public function exactMatch_noException(): void
    {
        RuleNameValidator::validateRuleNames(
            ['rules' => ['complexity.cyclomatic' => ['method' => ['warning' => 10]]]],
            'test.yaml',
            $this->createProvider(['complexity.cyclomatic']),
            '/path/to/test.yaml',
        );

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function forwardPrefixMatch_noException(): void
    {
        RuleNameValidator::validateRuleNames(
            ['rules' => ['complexity' => ['cyclomatic' => ['method' => ['warning' => 10]]]]],
            'test.yaml',
            $this->createProvider(['complexity.cyclomatic', 'complexity.cognitive']),
            '/path/to/test.yaml',
        );

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function reversePrefixMatch_noException(): void
    {
        RuleNameValidator::validateRuleNames(
            ['rules' => ['complexity.cyclomatic.method' => ['warning' => 10]]],
            'test.yaml',
            $this->createProvider(['complexity.cyclomatic']),
            '/path/to/test.yaml',
        );

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function unknownRuleName_throwsException(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessageMatches('/Unknown rule "nonexistent\.rule"/');

        RuleNameValidator::validateRuleNames(
            ['rules' => ['nonexistent.rule' => ['warning' => 10]]],
            'preset:strict',
            $this->createProvider(['complexity.cyclomatic']),
            '/path/to/preset.yaml',
        );
    }

    #[Test]
    public function emptyRulesSection_noException(): void
    {
        RuleNameValidator::validateRuleNames(
            ['rules' => []],
            'test.yaml',
            $this->createProvider(['complexity.cyclomatic']),
            '/path/to/test.yaml',
        );

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function noRulesSection_noException(): void
    {
        RuleNameValidator::validateRuleNames(
            ['format' => 'json'],
            'test.yaml',
            $this->createProvider(['complexity.cyclomatic']),
            '/path/to/test.yaml',
        );

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function multipleUnknownNames_allListedInException(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessageMatches('/nonexistent\.one/');
        $this->expectExceptionMessageMatches('/nonexistent\.two/');

        RuleNameValidator::validateRuleNames(
            ['rules' => [
                'nonexistent.one' => ['warning' => 5],
                'nonexistent.two' => ['warning' => 10],
            ]],
            'test.yaml',
            $this->createProvider(['complexity.cyclomatic']),
            '/path/to/test.yaml',
        );
    }

    #[Test]
    public function validateRuleNamesThrowsForUnknownRule(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessageMatches('/Unknown rule "bogus\.rule" in qmx\.yaml/');

        RuleNameValidator::validateRuleNames(
            ['rules' => ['bogus.rule' => ['warning' => 5]]],
            'qmx.yaml',
            $this->createProvider(['complexity.cyclomatic', 'cohesion.lcom4']),
            '/project/qmx.yaml',
        );
    }

    #[Test]
    public function validateRuleNamesSuggestsCloseMatch(): void
    {
        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessageMatches('/Unknown rule "complexty".*Did you mean "complexity"\?/');

        RuleNameValidator::validateRuleNames(
            ['rules' => ['complexty' => ['cyclomatic' => ['warning' => 10]]]],
            'qmx.yaml',
            $this->createProvider(['complexity', 'cohesion', 'coupling']),
            '/project/qmx.yaml',
        );
    }

    #[Test]
    public function validateRuleNamesNoSuggestionForDistantMatch(): void
    {
        try {
            RuleNameValidator::validateRuleNames(
                ['rules' => ['zzzzz' => ['warning' => 10]]],
                'qmx.yaml',
                $this->createProvider(['complexity.cyclomatic', 'cohesion.lcom4']),
                '/project/qmx.yaml',
            );
            self::fail('Expected ConfigLoadException');
        } catch (ConfigLoadException $e) {
            self::assertStringContainsString('Unknown rule "zzzzz"', $e->getMessage());
            self::assertStringNotContainsString('Did you mean', $e->getMessage());
        }
    }

    #[Test]
    public function validateRuleNamesListsMultipleUnknowns(): void
    {
        try {
            RuleNameValidator::validateRuleNames(
                ['rules' => [
                    'bogus.one' => ['warning' => 5],
                    'bogus.two' => ['warning' => 10],
                ]],
                'qmx.yaml',
                $this->createProvider(['complexity.cyclomatic']),
                '/project/qmx.yaml',
            );
            self::fail('Expected ConfigLoadException');
        } catch (ConfigLoadException $e) {
            self::assertStringContainsString('Unknown rule "bogus.one"', $e->getMessage());
            self::assertStringContainsString('Unknown rule "bogus.two"', $e->getMessage());
        }
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
