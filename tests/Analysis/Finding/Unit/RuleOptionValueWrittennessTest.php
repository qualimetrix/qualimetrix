<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Finding\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\DocumentSectionSchemaInterface;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\NodeSchema;

use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\ScalarForm;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\SectionDeclaration;
use Qualimetrix\Analysis\Configuration\Contract\Document\Schema\Shorthand;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\Document\AuthoredLayer;
use Qualimetrix\Analysis\Configuration\Document\AuthoredNode;
use Qualimetrix\Analysis\Configuration\Document\DocumentComposer;
use Qualimetrix\Analysis\Configuration\Document\DocumentSchema;
use Qualimetrix\Analysis\Finding\Contract\Rule\BandDirection;
use Qualimetrix\Analysis\Finding\Contract\Rule\ResolvedRuleOptionValues;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleOptionBand;
use Qualimetrix\Analysis\Finding\Contract\Rule\ThresholdParser;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionValueWrittenness;

#[CoversClass(RuleOptionValueWrittenness::class)]
final class RuleOptionValueWrittennessTest extends TestCase
{
    #[Test]
    public function itTreatsNullAsUnwritten(): void
    {
        self::assertFalse(RuleOptionValueWrittenness::isWritten(null));
    }

    #[Test]
    public function itTreatsFalseZeroAndEmptyStringAsWritten(): void
    {
        self::assertTrue(RuleOptionValueWrittenness::isWritten(false));
        self::assertTrue(RuleOptionValueWrittenness::isWritten(0));
        self::assertTrue(RuleOptionValueWrittenness::isWritten(''));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function provideValues(): iterable
    {
        yield 'null (the `~` case)' => [null];
        yield 'false' => [false];
        yield 'zero' => [0];
        yield 'empty string' => [''];
    }

    #[Test]
    #[DataProvider('provideValues')]
    public function itDefaultsAnUnwrittenThresholdAndJudgesEveryWrittenValue(mixed $value): void
    {
        if ($value === false || $value === '') {
            self::assertTrue(RuleOptionValueWrittenness::isWritten($value));
            self::expectException(\Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal::class);
            self::expectExceptionMessage($value === false
                ? '"rules.fixture.threshold" in configuration file "/project/qmx.yaml" must be number, got bool.'
                : '"rules.fixture.threshold" in configuration file "/project/qmx.yaml" must be number, got string.');
        }
        $result = self::parse(['threshold' => $value], 'warning', 'error');

        if (RuleOptionValueWrittenness::isWritten($value)) {
            self::assertSame($value, $result['warning'], 'A written threshold must select simple mode.');
            self::assertSame($value, $result['error'], 'A written threshold must select simple mode.');
        } else {
            self::assertSame(10, $result['warning'], 'An unwritten threshold must fall back to the default.');
            self::assertSame(20, $result['error'], 'An unwritten threshold must fall back to the default.');
        }
    }

    #[Test]
    public function itAsksOnlyAboutTheValueNotAboutWhichSpellingCarriesIt(): void
    {
        $writtenUnderPrimarySpelling = ['max_warning' => 5];
        $writtenUnderLegacySpelling = ['maxWarning' => 5];

        $viaPrimary = self::parse($writtenUnderPrimarySpelling, 'max-warning', 'max-error');
        $viaLegacy = self::parse($writtenUnderLegacySpelling, 'max-warning', 'max-error');

        self::assertSame($viaPrimary['warning'], $viaLegacy['warning']);
        self::assertTrue(RuleOptionValueWrittenness::isWritten($writtenUnderPrimarySpelling['max_warning']));
        self::assertTrue(RuleOptionValueWrittenness::isWritten($writtenUnderLegacySpelling['maxWarning']));
    }

    /** @param array<string, mixed> $config
     * @return array{warning: int|float, error: int|float}
     */
    private static function parse(array $config, string $warningKey, string $errorKey): array
    {
        $number = NodeSchema::scalar(ScalarForm::Number);
        $entry = NodeSchema::map([$warningKey => $number, $errorKey => $number], Shorthand::spreading('threshold', [$warningKey, $errorKey]));
        $section = new class ($entry) implements DocumentSectionSchemaInterface {
            public function __construct(private readonly NodeSchema $entry) {}
            public function declaration(): SectionDeclaration
            {
                return new SectionDeclaration('rules', NodeSchema::namedMap($this->entry));
            }
        };
        $document = DocumentComposer::compose(new DocumentSchema([$section]), [new AuthoredLayer(
            ConfigurationOrigin::of(ConfigurationSource::ConfigFile, '/project/qmx.yaml'),
            AuthoredNode::fromPlain(['rules' => ['fixture' => $config]]),
        )]);
        return ThresholdParser::parse(new ResolvedRuleOptionValues($document, 'fixture'), new RuleOptionBand('threshold', $warningKey, $errorKey, BandDirection::Rising), 10, 20);
    }
}
