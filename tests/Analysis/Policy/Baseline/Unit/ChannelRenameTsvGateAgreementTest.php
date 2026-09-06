<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Baseline\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use QmxFindingGate\MetricVocabulary;
use QmxFindingGate\RenameMaps;
use Qualimetrix\Tests\Analysis\Policy\Baseline\Fixtures\ChannelRenameTsvCorpus;
use Throwable;

/**
 * The other half of the shared corpus: what the finding gate's reader does
 * with the same lines.
 *
 * Without this, "the carry reads the same format the gate declares renames
 * in" is a sentence in a plan. With it, a change to either reader that moves
 * a verdict has to move the corpus too, and the five deliberate divergences
 * are pinned as declarations rather than discovered later as surprises.
 *
 * The gate is not on the production autoloader — `composer.json` maps
 * `Qualimetrix\` to `src/` and nothing to `scripts/` — so its classes are
 * required by hand. That is the same fact the corpus exists because of: the
 * two readers cannot be one.
 */
final class ChannelRenameTsvGateAgreementTest extends TestCase
{
    /** The other four maps a gate load insists on finding beside `channels.tsv`. */
    private const array SIBLING_MAPS = ['symbols.tsv', 'metric-keys.tsv', 'inputs.tsv', 'report-values.tsv'];

    private string $tempDir;

    public static function setUpBeforeClass(): void
    {
        $gate = \dirname(__DIR__, 5) . '/scripts/finding-gate';

        foreach (['GateError.php', 'Fs.php', 'Tsv.php', 'MetricVocabulary.php', 'RenameMaps.php'] as $file) {
            require_once $gate . '/' . $file;
        }
    }

    /**
     * @return iterable<string, array{string, bool, string}>
     */
    public static function provideCorpus(): iterable
    {
        foreach (ChannelRenameTsvCorpus::cases() as $case) {
            yield $case['id'] => [$case['contents'], $case['gate'], $case['note']];
        }
    }

    protected function setUp(): void
    {
        $this->tempDir = (string) tempnam(sys_get_temp_dir(), 'qmx-gate-corpus-');
        unlink($this->tempDir);
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tempDir . '/*');

        foreach (\is_array($files) ? $files : [] as $file) {
            unlink($file);
        }

        rmdir($this->tempDir);
    }

    #[Test]
    #[DataProvider('provideCorpus')]
    public function itAnswersTheSharedCorpusAsDeclared(string $contents, bool $accepted, string $note): void
    {
        file_put_contents($this->tempDir . '/channels.tsv', $contents);

        foreach (self::SIBLING_MAPS as $sibling) {
            file_put_contents($this->tempDir . '/' . $sibling, "old\tnew\treason\n");
        }

        $refusal = null;

        try {
            RenameMaps::load($this->tempDir, MetricVocabulary::none());
        } catch (Throwable $e) {
            $refusal = $e;
        }

        self::assertSame($accepted, $refusal === null, $note . ' ' . ($refusal?->getMessage() ?? ''));
    }
}
