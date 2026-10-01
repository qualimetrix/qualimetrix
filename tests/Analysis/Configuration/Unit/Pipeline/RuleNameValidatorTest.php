<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Unit\Pipeline;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\DocumentSectionSchemaInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\ScalarForm;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\SectionDeclaration;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\Document\AuthoredLayer;
use Qualimetrix\Analysis\Configuration\Document\AuthoredNode;
use Qualimetrix\Analysis\Configuration\Document\DocumentComposer;
use Qualimetrix\Analysis\Configuration\Document\DocumentSchema;
use Qualimetrix\Analysis\Evidence\Complexity\ComplexityOptions;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Analysis\Finding\Contract\RuleMetadata;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RulesSection;
use Qualimetrix\Analysis\Finding\Selection\RuleNameJudge;

#[CoversClass(RuleNameJudge::class)]
final class RuleNameValidatorTest extends TestCase
{
    #[Test]
    public function itAcceptsARuleNameThatExactlyMatchesAKnownRule(): void
    {
        $this->compose(
            ['rules' => ['complexity.ccn' => ['callable' => ['warning' => 10]]]],
            'test.yaml',
            $this->knownNames(['complexity.ccn']),
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
        $this->expectException(ConfigurationRefusal::class);

        $this->compose(
            ['rules' => ['complexity' => ['cyclomatic' => ['callable' => ['warning' => 10]]]]],
            'test.yaml',
            $this->knownNames(['complexity.ccn', 'complexity.cognitive']),
            '/path/to/test.yaml',
        );
    }

    #[Test]
    public function itRejectsAKeyRefiningARuleNameWithAChannelSuffix(): void
    {
        // A `rules:` key owns an options object; a channel does not have one.
        $this->expectException(ConfigurationRefusal::class);

        $this->compose(
            ['rules' => ['complexity.cyclomatic.callable' => ['warning' => 10]]],
            'test.yaml',
            $this->knownNames(['complexity.ccn']),
            '/path/to/test.yaml',
        );
    }

    #[Test]
    public function itRejectsAWildcardKey(): void
    {
        $this->expectException(ConfigurationRefusal::class);

        $this->compose(
            ['rules' => ['complexity.*' => ['warning' => 10]]],
            'test.yaml',
            $this->knownNames(['complexity.ccn']),
            '/path/to/test.yaml',
        );
    }

    #[Test]
    public function itRejectsAnUnknownRuleName(): void
    {
        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessageMatches('/Rule option owner "nonexistent\.rule"/');

        $this->compose(
            ['rules' => ['nonexistent.rule' => ['warning' => 10]]],
            'preset:strict',
            $this->knownNames(['complexity.ccn']),
            '/path/to/preset.yaml',
        );
    }

    #[Test]
    public function itAcceptsAnEmptyRulesSection(): void
    {
        $this->compose(
            ['rules' => []],
            'test.yaml',
            $this->knownNames(['complexity.ccn']),
            '/path/to/test.yaml',
        );

        self::expectNotToPerformAssertions();
    }

    #[Test]
    public function itAcceptsConfigWithoutARulesSection(): void
    {
        $this->compose(
            ['format' => 'json'],
            'test.yaml',
            $this->knownNames(['complexity.ccn']),
            '/path/to/test.yaml',
        );

        self::expectNotToPerformAssertions();
    }

    #[Test]
    public function itRefusesTheFirstUnknownOwnerBeforeInspectingTheNext(): void
    {
        try {
            $this->compose(
                ['rules' => ['nonexistent.one' => ['warning' => 5], 'nonexistent.two' => ['warning' => 10]]],
                'test.yaml',
                ['complexity.ccn'],
                '/path/to/test.yaml',
            );
            self::fail('Expected ConfigurationRefusal');
        } catch (ConfigurationRefusal $refusal) {
            self::assertSame('Rule option owner "nonexistent.one" does not match any registered producer rule.', $refusal->summary());
            self::assertSame('/path/to/test.yaml', $refusal->sources()[0]->locator());
            self::assertNotNull($refusal->position());
            self::assertSame(['rules', 'nonexistent.one'], $refusal->position()->segments);
        }
    }

    #[Test]
    public function itNamesTheSourceFileInTheExceptionForAnUnknownRule(): void
    {
        try {
            $this->compose(['rules' => ['bogus.rule' => ['warning' => 5]]], 'qmx.yaml', ['complexity.ccn', 'cohesion.lcom4'], '/project/qmx.yaml');
            self::fail('Expected ConfigurationRefusal');
        } catch (ConfigurationRefusal $refusal) {
            self::assertSame('Rule option owner "bogus.rule" does not match any registered producer rule.', $refusal->summary());
            self::assertSame('/project/qmx.yaml', $refusal->sources()[0]->locator());
            self::assertNotNull($refusal->position());
            self::assertSame(['rules', 'bogus.rule'], $refusal->position()->segments);
        }
    }

    #[Test]
    public function itSuggestsACloseMatchForAMisspelledRuleName(): void
    {
        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessageMatches('/Rule option owner "complexty".*Did you mean "complexity"\?/');

        $this->compose(
            ['rules' => ['complexty' => ['cyclomatic' => ['warning' => 10]]]],
            'qmx.yaml',
            $this->knownNames(['complexity', 'cohesion', 'coupling']),
            '/project/qmx.yaml',
        );
    }

    #[Test]
    public function itOmitsASuggestionWhenNoKnownRuleIsClose(): void
    {
        try {
            $this->compose(
                ['rules' => ['zzzzz' => ['warning' => 10]]],
                'qmx.yaml',
                $this->knownNames(['complexity.ccn', 'cohesion.lcom4']),
                '/project/qmx.yaml',
            );
            self::fail('Expected ConfigurationRefusal');
        } catch (ConfigurationRefusal $e) {
            self::assertStringContainsString('Rule option owner "zzzzz"', $e->getMessage());
            self::assertStringNotContainsString('Did you mean', $e->getMessage());
        }
    }

    /**
     * Regression for the rename in ADR 0060: `design.lcom` is 3 Levenshtein
     * edits from the unrelated `design.noc` but 4 from the rule it was
     * actually renamed to, `cohesion.lcom`. Raw distance alone suggested
     * `design.noc`; the leaf (`lcom`) match must win instead.
     */
    #[Test]
    public function itSuggestsTheRenamedRuleByItsSharedLeafNotByRawDistance(): void
    {
        self::expectException(ConfigurationRefusal::class);
        self::expectExceptionMessageMatches('/Rule option owner "design\.lcom".*Did you mean "cohesion\.lcom"\?/');

        $this->compose(
            ['rules' => ['design.lcom' => ['warning' => 10]]],
            'qmx.yaml',
            $this->knownNames(['design.noc', 'cohesion.lcom']),
            '/project/qmx.yaml',
        );
    }

    #[Test]
    public function itReportsEachUnknownRuleNameSeparately(): void
    {
        foreach (['bogus.one' => 5, 'bogus.two' => 10] as $name => $warning) {
            try {
                $this->compose(['rules' => [$name => ['warning' => $warning]]], 'qmx.yaml', ['complexity.ccn'], '/project/qmx.yaml');
                self::fail('Expected ConfigurationRefusal');
            } catch (ConfigurationRefusal $refusal) {
                self::assertSame(\sprintf('Rule option owner "%s" does not match any registered producer rule.', $name), $refusal->summary());
                self::assertNotNull($refusal->position());
                self::assertSame(['rules', $name], $refusal->position()->segments);
            }
        }
    }

    /**
     * @param list<string> $names
     *
     * @return list<string>
     */
    private function knownNames(array $names): array
    {
        return $names;
    }

    /** @param array<string, mixed> $data
     * @param list<string> $names */
    private function compose(array $data, string $source, array $names, string $path): void
    {
        $execution = self::createStub(RuleExecutionInterface::class);
        $execution->method('allRules')->willReturn(array_map(static fn(string $name): RuleMetadata => new RuleMetadata($name, ComplexityOptions::class, '', [], false), $names));
        $format = new class implements DocumentSectionSchemaInterface {
            public function declaration(): SectionDeclaration
            {
                return new SectionDeclaration('format', NodeSchema::scalar(ScalarForm::String));
            }
        };
        DocumentComposer::compose(new DocumentSchema([new RulesSection($execution, 'rules'), $format]), [new AuthoredLayer(
            ConfigurationOrigin::of(str_starts_with($source, 'preset:') ? ConfigurationSource::Preset : ConfigurationSource::ConfigFile, $path),
            AuthoredNode::fromPlain($data),
        )]);
    }
}
