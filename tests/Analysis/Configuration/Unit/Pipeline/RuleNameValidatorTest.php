<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit\Pipeline;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Exception\ConfigLoadException;
use Qualimetrix\Analysis\Configuration\Contract\KnownRuleNamesProviderInterface;
use Qualimetrix\Analysis\Configuration\Pipeline\RuleNameValidator;

#[CoversClass(RuleNameValidator::class)]
final class RuleNameValidatorTest extends TestCase
{
    #[Test]
    public function exactMatch_noException(): void
    {
        RuleNameValidator::validateRuleNames(
            ['rules' => ['complexity.cyclomatic' => ['callable' => ['warning' => 10]]]],
            'test.yaml',
            $this->createProvider(['complexity.cyclomatic']),
            '/path/to/test.yaml',
        );

        self::expectNotToPerformAssertions();
    }

    #[Test]
    public function itRejectsAGroupKeyThatNamesNoRule(): void
    {
        // `rules: { complexity: ... }` used to pass validation by prefix and
        // then configure nothing at all, because options are applied by exact
        // key. Passing validation was the bug.
        $this->expectException(ConfigLoadException::class);

        RuleNameValidator::validateRuleNames(
            ['rules' => ['complexity' => ['cyclomatic' => ['callable' => ['warning' => 10]]]]],
            'test.yaml',
            $this->createProvider(['complexity.cyclomatic', 'complexity.cognitive']),
            '/path/to/test.yaml',
        );
    }

    #[Test]
    public function itRejectsAKeyRefiningARuleNameWithAChannelSuffix(): void
    {
        // A `rules:` key owns an options object; a channel does not have one.
        $this->expectException(ConfigLoadException::class);

        RuleNameValidator::validateRuleNames(
            ['rules' => ['complexity.cyclomatic.callable' => ['warning' => 10]]],
            'test.yaml',
            $this->createProvider(['complexity.cyclomatic']),
            '/path/to/test.yaml',
        );
    }

    #[Test]
    public function itRejectsAWildcardKey(): void
    {
        $this->expectException(ConfigLoadException::class);

        RuleNameValidator::validateRuleNames(
            ['rules' => ['complexity.*' => ['warning' => 10]]],
            'test.yaml',
            $this->createProvider(['complexity.cyclomatic']),
            '/path/to/test.yaml',
        );
    }

    #[Test]
    public function unknownRuleName_throwsException(): void
    {
        self::expectException(ConfigLoadException::class);
        self::expectExceptionMessageMatches('/Unknown rule "nonexistent\.rule"/');

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

        self::expectNotToPerformAssertions();
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

        self::expectNotToPerformAssertions();
    }

    #[Test]
    public function multipleUnknownNames_allListedInException(): void
    {
        self::expectException(ConfigLoadException::class);
        self::expectExceptionMessageMatches('/nonexistent\.one/');
        self::expectExceptionMessageMatches('/nonexistent\.two/');

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
        self::expectException(ConfigLoadException::class);
        self::expectExceptionMessageMatches('/Unknown rule "bogus\.rule" in qmx\.yaml/');

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
        self::expectException(ConfigLoadException::class);
        self::expectExceptionMessageMatches('/Unknown rule "complexty".*Did you mean "complexity"\?/');

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
        $provider = self::createStub(KnownRuleNamesProviderInterface::class);
        $provider->method('getKnownRuleNames')->willReturn($names);

        return $provider;
    }
}
