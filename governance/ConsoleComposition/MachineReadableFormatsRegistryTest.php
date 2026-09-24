<?php

declare(strict_types=1);

namespace Qualimetrix\Governance\ConsoleComposition;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Infrastructure\Console\Refusal\MachineReadableFormats;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Reporting\FindingProjection\SuppressionComposition;
use Qualimetrix\Reporting\Formatter\FormatterRegistryInterface;
use Qualimetrix\Reporting\FormatterContext;
use Qualimetrix\Reporting\ReportBuilder;

/**
 * Ties `MachineReadableFormats`'s "closed and measured" claim to the actual
 * `FormatterRegistry`, instead of leaving the two lists free to drift:
 * formatters are registered automatically
 * by name (CLAUDE.md §7 "Adding a new formatter"), so a new one never
 * touches `MachineReadableFormats.php` or its unit test by construction —
 * only this test, which boots the real DI container, catches a format that
 * was registered but never classified.
 *
 * Membership alone did not keep the classification true: `health` sat among
 * the JSON formats while its formatter printed a text table, so a refusal
 * under `--format=health` put a JSON envelope where a reader expected the
 * table. {@see self::itClassifiesEachFormatByWhatItsFormatterPrints()} renders
 * every formatter and holds the list to what came out.
 */
#[CoversClass(MachineReadableFormats::class)]
final class MachineReadableFormatsRegistryTest extends TestCase
{
    #[Test]
    public function itClassifiesEveryVisiblyRegisteredFormat(): void
    {
        $visible = self::registry()->getAvailableNames();
        $known = MachineReadableFormats::knownFormats();

        // Every format a user can select (`--format=`) has an explicit
        // JSON/not-JSON opinion here. A newly registered formatter fails
        // this line, not the `false` (no envelope) MachineReadableFormats
        // would otherwise default to.
        self::assertSame(
            [],
            array_values(array_diff($visible, $known)),
            'A registered formatter is not classified by MachineReadableFormats::knownFormats(). '
            . 'Add it to JSON_DOCUMENT_FORMATS or NON_JSON_FORMATS.',
        );
    }

    #[Test]
    public function itKnowsOnlyFormatsTheRegistryActuallyHas(): void
    {
        $registry = self::registry();

        foreach (MachineReadableFormats::knownFormats() as $format) {
            self::assertTrue(
                $registry->has($format),
                \sprintf('MachineReadableFormats classifies "%s", but FormatterRegistry has no such formatter.', $format),
            );
        }
    }

    #[Test]
    public function itAccountsForEveryVisibleFormatExactlyOnce(): void
    {
        // `text-verbose` is the one registered formatter FormatterRegistry
        // hides from `getAvailableNames()` (deprecated, still selectable);
        // it is still classified in `knownFormats()`, so the closed set is
        // "visible names" + that one named hidden exception, not a free
        // superset.
        $visible = self::registry()->getAvailableNames();
        $known = MachineReadableFormats::knownFormats();

        /** @var list<string> $expectedKnown */
        $expectedKnown = [...$visible, 'text-verbose'];
        sort($expectedKnown);
        sort($known);

        self::assertSame($expectedKnown, $known);
    }

    #[Test]
    public function itClassifiesEachFormatByWhatItsFormatterPrints(): void
    {
        $registry = self::registry();
        // The `suppressed` formatter refuses a report whose run never built the
        // composition; an empty one is what a run that suppressed nothing has.
        $report = ReportBuilder::create()->suppressionComposition(new SuppressionComposition([]))->build();

        foreach (MachineReadableFormats::knownFormats() as $format) {
            $printed = $registry->get($format)->format($report, new FormatterContext(useColor: false));

            self::assertSame(
                MachineReadableFormats::carriesJson($format),
                json_validate($printed),
                \sprintf(
                    'MachineReadableFormats says "%s" %s a JSON document, but its formatter printed %s.',
                    $format,
                    MachineReadableFormats::carriesJson($format) ? 'carries' : 'does not carry',
                    json_validate($printed) ? 'one' : 'something else',
                ),
            );
        }
    }

    private static function registry(): FormatterRegistryInterface
    {
        $container = (new ContainerFactory())->create();
        $registry = $container->get(FormatterRegistryInterface::class);
        self::assertInstanceOf(FormatterRegistryInterface::class, $registry);

        return $registry;
    }
}
