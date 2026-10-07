<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Policy\Architecture\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Policy\Architecture\Contract\ArchitectureChannels;
use Qualimetrix\Analysis\Policy\Architecture\LayerDeclaration\LayerOverlapDiagnostic;
use Qualimetrix\Analysis\Policy\Architecture\Observation\LayerEvidenceCollector;
use Qualimetrix\Infrastructure\Console\Command\CheckCommand;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(LayerOverlapDiagnostic::class)]
#[CoversClass(LayerEvidenceCollector::class)]
final class LayerOverlapIntegrationTest extends TestCase
{
    private string $fixture;

    protected function setUp(): void
    {
        $this->fixture = sys_get_temp_dir() . '/qmx-layer-overlap-' . bin2hex(random_bytes(6));
        foreach (['Legacy', 'Modern'] as $directory) {
            mkdir($this->fixture . '/src/' . $directory, 0777, true);
        }
        file_put_contents($this->fixture . '/composer.json', '{"autoload":{"psr-4":{"App\\\\":"src/"}}}');
        file_put_contents($this->fixture . '/src/Legacy/UserRepository.php', '<?php namespace App\\Legacy; class UserRepository {}');
        file_put_contents($this->fixture . '/src/Modern/OrderRepository.php', '<?php namespace App\\Modern; class OrderRepository {}');
    }

    protected function tearDown(): void
    {
        foreach (['src/Legacy/UserRepository.php', 'src/Modern/OrderRepository.php', 'composer.json', 'qmx.yaml'] as $path) {
            @unlink($this->fixture . '/' . $path);
        }
        foreach (['src/Legacy', 'src/Modern', 'src', ''] as $path) {
            @rmdir($this->fixture . '/' . $path);
        }
    }

    #[Test]
    public function itReportsPartialNonPatternLossAsProjectInformation(): void
    {
        [$exit, $findings] = $this->check(self::layers());
        self::assertSame(0, $exit);
        self::assertSame([], self::on($findings, ArchitectureChannels::POTENTIAL_SHADOW_DIAGNOSTIC_NAME));
        $overlap = self::on($findings, ArchitectureChannels::LAYER_OVERLAP_DIAGNOSTIC_NAME);
        self::assertCount(1, $overlap);
        self::assertSame('info', $overlap[0]['severity']);
        self::assertStringContainsString('1 class(es)', $overlap[0]['message']);
        self::assertStringContainsString('App\\Legacy\\UserRepository', $overlap[0]['message']);
        self::assertStringContainsString('earlier layer "legacy"', $overlap[0]['message']);
        self::assertSame([], self::on($findings, ArchitectureChannels::UNREACHABLE_LAYER_DIAGNOSTIC_NAME));

        unlink($this->fixture . '/src/Modern/OrderRepository.php');
        file_put_contents($this->fixture . '/src/Legacy/UserRepository.php', '<?php namespace App\\Legacy; class UserRepository { public \\Vendor\\RemoteRepository $remote; }');
        [, $edgeOnly] = $this->check(self::layers());
        self::assertSame([], self::on($edgeOnly, ArchitectureChannels::LAYER_OVERLAP_DIAGNOSTIC_NAME));
        self::assertSame([], self::on($edgeOnly, ArchitectureChannels::UNREACHABLE_LAYER_DIAGNOSTIC_NAME));
    }

    #[Test]
    public function itReportsACompleteNonPatternLossAsAnUnreachableCause(): void
    {
        unlink($this->fixture . '/src/Modern/OrderRepository.php');
        [$exit, $findings] = $this->check(self::layers());
        self::assertSame(2, $exit);
        self::assertSame([], self::on($findings, ArchitectureChannels::LAYER_OVERLAP_DIAGNOSTIC_NAME));
        self::assertSame([], self::on($findings, ArchitectureChannels::POTENTIAL_SHADOW_DIAGNOSTIC_NAME));
        $unreachable = self::on($findings, ArchitectureChannels::UNREACHABLE_LAYER_DIAGNOSTIC_NAME);
        self::assertCount(1, $unreachable);
        self::assertStringContainsString('taken by earlier layer "legacy"', $unreachable[0]['message']);
        self::assertStringContainsString('App\\Legacy\\UserRepository', $unreachable[0]['message']);
    }

    #[Test]
    public function itKeepsLatePatternLossOutOfOverlapButNamesItsUnreachableCause(): void
    {
        [$exit, $findings] = $this->check("architecture:\n  layers:\n    - name: repos\n      suffix: ['Repository']\n    - name: rest\n      patterns: ['**']\n");
        self::assertSame(2, $exit);
        self::assertSame([], self::on($findings, ArchitectureChannels::LAYER_OVERLAP_DIAGNOSTIC_NAME));
        self::assertSame([], self::on($findings, ArchitectureChannels::POTENTIAL_SHADOW_DIAGNOSTIC_NAME));
        $unreachable = self::on($findings, ArchitectureChannels::UNREACHABLE_LAYER_DIAGNOSTIC_NAME);
        self::assertCount(1, $unreachable);
        self::assertStringContainsString('taken by earlier layer "repos"', $unreachable[0]['message']);
    }

    #[Test]
    public function itNamesTheSymbolsRemovedByTheLayersOwnExclude(): void
    {
        [$exit, $findings] = $this->check("architecture:\n  layers:\n    - name: excluded\n      patterns: ['App\\Legacy\\**']\n      exclude:\n        suffix: ['Repository']\n    - name: rest\n      patterns: ['**']\n");
        self::assertSame(2, $exit);
        $unreachable = self::on($findings, ArchitectureChannels::UNREACHABLE_LAYER_DIAGNOSTIC_NAME);
        self::assertCount(1, $unreachable);
        self::assertStringContainsString('1 symbol(s) removed by its own "exclude"', $unreachable[0]['message']);
        self::assertStringContainsString('App\\Legacy\\UserRepository', $unreachable[0]['message']);
        self::assertStringContainsString('Narrow or remove', $unreachable[0]['recommendation']);
    }

    #[Test]
    public function itFiltersOverlapUnderOnlyAndHonoursItsProducerOffSwitch(): void
    {
        [, $only] = $this->check(self::layers(), ['--only-rule' => ['complexity.ccn']]);
        self::assertSame([], self::on($only, ArchitectureChannels::LAYER_OVERLAP_DIAGNOSTIC_NAME));
        [, $disabled] = $this->check(self::layers(), ['--disable-rule' => ['architecture.layer-declaration']]);
        self::assertSame([], self::on($disabled, ArchitectureChannels::LAYER_OVERLAP_DIAGNOSTIC_NAME));
    }

    #[Test]
    public function itSamplesOwnExclusionsAtDependencyEndsOutsideTheAnalysedSet(): void
    {
        file_put_contents($this->fixture . '/src/Legacy/UserRepository.php', '<?php namespace App\\Legacy; class UserRepository { public function read(): \\Vendor\\RemovedRepository { return new \\Vendor\\RemovedRepository(); } }');
        [, $findings] = $this->check("architecture:\n  layers:\n    - name: vendor\n      patterns: ['Vendor\\**']\n      exclude:\n        suffix: ['Repository']\n    - name: own\n      patterns: ['App\\**']\n");
        $unreachable = self::on($findings, ArchitectureChannels::UNREACHABLE_LAYER_DIAGNOSTIC_NAME);
        self::assertCount(1, $unreachable);
        self::assertStringContainsString('1 symbol(s) removed by its own "exclude"', $unreachable[0]['message']);
        self::assertStringContainsString('Vendor\\RemovedRepository', $unreachable[0]['message']);
    }

    #[Test]
    public function itOpensAnOutsideContestWithTheCauseTheRunEstablished(): void
    {
        file_put_contents($this->fixture . '/src/Legacy/UserRepository.php', '<?php namespace App\\Legacy; class UserRepository { public \\Vendor\\RemoteRepository $remote; }');
        [, $findings] = $this->check("architecture:\n  layers:\n    - name: all\n      patterns: ['**']\n      exclude:\n        extends: ['Vendor\\MissingBase']\n    - name: vendor\n      patterns: ['Vendor\\**']\n");
        $unreachable = self::on($findings, ArchitectureChannels::UNREACHABLE_LAYER_DIAGNOSTIC_NAME);
        self::assertCount(1, $unreachable);
        self::assertStringStartsWith('Layer "vendor" was assigned no symbol during analysis.', $unreachable[0]['message']);
        self::assertStringContainsString('Its criteria match 1 symbol(s) outside the analysed paths', $unreachable[0]['message']);
        self::assertStringContainsString('an earlier layer holds through an "exclude"', $unreachable[0]['message']);
        self::assertStringNotContainsString('Possible causes', $unreachable[0]['message']);
        self::assertStringContainsString('before the earlier layer', $unreachable[0]['recommendation']);
        self::assertCount(1, self::on($findings, ArchitectureChannels::DOUBTED_ASSIGNMENT_DIAGNOSTIC_NAME));
    }

    private static function layers(): string
    {
        return "architecture:\n  layers:\n    - name: legacy\n      patterns: ['App\\Legacy\\**']\n    - name: repos\n      suffix: ['Repository']\n";
    }

    /** @param array<string, mixed> $arguments
     * @return array{int, list<array<string, mixed>>}
     */
    private function check(string $yaml, array $arguments = []): array
    {
        file_put_contents($this->fixture . '/qmx.yaml', $yaml);
        $before = getcwd();
        self::assertNotFalse($before);
        try {
            chdir($this->fixture);
            $command = (new ContainerFactory())->create()->get(CheckCommand::class);
            self::assertInstanceOf(CheckCommand::class, $command);
            $tester = new CommandTester($command);
            $exit = $tester->execute([
                'paths' => ['src'], '--config' => 'qmx.yaml', '--format' => 'json',
                '--no-progress' => true, '--no-cache' => true, '--workers' => 0, '--fail-on' => 'none', ...$arguments,
            ]);
            $report = json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);
            return [$exit, $report['violations']];
        } finally {
            chdir($before);
        }
    }

    /** @param list<array<string, mixed>> $findings
     * @return list<array<string, mixed>>
     */
    private static function on(array $findings, string $channel): array
    {
        return array_values(array_filter($findings, static fn(array $finding): bool => $finding['rule'] === $channel));
    }
}
