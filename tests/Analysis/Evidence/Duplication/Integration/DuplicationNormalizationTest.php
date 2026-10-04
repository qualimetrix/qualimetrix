<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Evidence\Duplication\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Evidence\Duplication\CodeDuplicationOptions;
use Qualimetrix\Analysis\Evidence\Duplication\DuplicationDetector;
use Qualimetrix\Analysis\Evidence\Duplication\DuplicationResultProvider;
use Qualimetrix\Analysis\Evidence\Duplication\Matching\DuplicateBlock;
use Qualimetrix\Analysis\Evidence\Duplication\Normalization\TokenNormalizer;
use Qualimetrix\Analysis\Finding\Contract\RuleMetadata;
use Qualimetrix\Analysis\Finding\RuleConfiguration\RuleOptionsRegistry;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Tests\Analysis\Finding\Support\ResolvedOptionsFixture;
use SplFileInfo;

#[CoversClass(DuplicationDetector::class)]
#[CoversClass(TokenNormalizer::class)]
final class DuplicationNormalizationTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/qmx-duplication-normalization-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        $files = glob($this->directory . '/*.php');
        foreach ($files === false ? [] : $files as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    #[Test]
    public function itMatchesEqualHtmlButExcludesADifferentMarkupCopy(): void
    {
        $php = '<?php function same($value) { $result = $value + 1; return $result * 2; }';
        $minTokens = (new TokenNormalizer())->normalize($php)->count() + 1;
        $blocks = $this->detect($minTokens, [
            'First.php' => "<p> A </p>\n" . $php,
            'Second.php' => "<p>\tA\n</p>\n" . $php,
            'Different.php' => "<div> A </div>\n" . $php,
        ]);

        self::assertNotEmpty($blocks);
        $files = [];
        foreach ($blocks as $block) {
            foreach ($block->locations as $location) {
                $files[$location->file->value()] = true;
            }
        }
        self::assertArrayHasKey('First.php', $files);
        self::assertArrayHasKey('Second.php', $files);
        self::assertArrayNotHasKey('Different.php', $files);
    }

    #[Test]
    public function itMatchesKeywordCastAndMagicConstantCaseThroughTheDetector(): void
    {
        $upper = '<?php FuNcTiOn same($value) { ReTuRn (INT)$value + (__dIr__ ? 1 : 2); }';
        $lower = '<?php function same($value) { return (int)$value + (__DIR__ ? 1 : 2); }';
        $minTokens = (new TokenNormalizer())->normalize($lower)->count();

        $blocks = $this->detect($minTokens, ['Upper.php' => $upper, 'Lower.php' => $lower]);

        self::assertNotEmpty($blocks);
        self::assertCount(2, $blocks[0]->locations);
    }

    #[Test]
    public function itDetectsCp1251HtmlWithAStableHexContentHash(): void
    {
        $php = '<?php function same($value) { return $value + 1; }';
        $minTokens = (new TokenNormalizer())->normalize($php)->count() + 1;
        $source = "\xcf\xf0\xee\xe2\xe5\xf0\xea\xe0\n" . $php;

        $blocks = $this->detect($minTokens, ['First.php' => $source, 'Second.php' => $source]);

        self::assertNotEmpty($blocks);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $blocks[0]->contentHash);
    }

    /**
     * @param array<string, string> $contents
     *
     * @return list<DuplicateBlock>
     */
    private function detect(int $minTokens, array $contents): array
    {
        $metadata = [new RuleMetadata('duplication.clone', CodeDuplicationOptions::class, '', [], false)];
        $configuration = ResolvedOptionsFixture::authoredConfiguration(['rules' => [
            'duplication.clone' => ['min_tokens' => $minTokens, 'min_lines' => 1],
        ]], $metadata);
        $registry = new RuleOptionsRegistry();
        $registry->replace(ResolvedOptionsFixture::ready($configuration, $metadata));
        $provider = new DuplicationResultProvider();
        $files = [];
        foreach ($contents as $name => $content) {
            $path = $this->directory . '/' . $name;
            file_put_contents($path, $content);
            $files[] = new SplFileInfo($path);
        }

        (new DuplicationDetector($registry, $provider))->inspect($files, AbsolutePath::fromString($this->directory));

        return $provider->all();
    }
}
