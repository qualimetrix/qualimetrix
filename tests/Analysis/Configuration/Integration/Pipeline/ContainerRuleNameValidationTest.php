<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Configuration\Integration\Pipeline;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationPipelineInterface;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationResolutionRequest;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Pipeline\RuleNameValidator;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;

/**
 * The file and preset stages validate rule names only when a provider of
 * known names is wired in, and the argument is optional: a missing alias
 * would switch the check off without a word, and every unit test would stay
 * green because each passes its own stub. Only the production container
 * shows the wiring, so the refusal is asked of it through both doors.
 */
#[CoversClass(RuleNameValidator::class)]
final class ContainerRuleNameValidationTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/qmx-rule-name-wiring-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (self::filesIn($this->directory) as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    #[Test]
    public function itRefusesAnUnknownRuleNameInTheConfigurationFile(): void
    {
        file_put_contents($this->directory . '/qmx.yaml', "rules:\n  coupling.classrank:\n    enabled: false\n");

        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('Unknown rule "coupling.classrank"');

        $this->resolve(new ConfigurationResolutionRequest(AbsolutePath::fromString($this->directory), null, []));
    }

    #[Test]
    public function itRefusesAnUnknownRuleNameInAPreset(): void
    {
        file_put_contents($this->directory . '/team.yaml', "rules:\n  coupling.classrank:\n    enabled: false\n");

        $this->expectException(ConfigurationRefusal::class);
        $this->expectExceptionMessage('Unknown rule "coupling.classrank"');

        $this->resolve(new ConfigurationResolutionRequest(
            AbsolutePath::fromString($this->directory),
            null,
            [$this->directory . '/team.yaml'],
        ));
    }

    /** The lawful neighbour: a registered rule name is accepted by the same wiring. */
    #[Test]
    public function itAcceptsARegisteredRuleName(): void
    {
        file_put_contents($this->directory . '/qmx.yaml', "rules:\n  coupling.class-rank:\n    enabled: false\n");

        $this->resolve(new ConfigurationResolutionRequest(AbsolutePath::fromString($this->directory), null, []));

        $this->addToAssertionCount(1);
    }

    /** @return list<string> */
    private static function filesIn(string $directory): array
    {
        $files = glob($directory . '/*');

        return $files === false ? [] : $files;
    }

    private function resolve(ConfigurationResolutionRequest $request): void
    {
        $pipeline = (new ContainerFactory())->create()->get(ConfigurationPipelineInterface::class);
        self::assertInstanceOf(ConfigurationPipelineInterface::class, $pipeline);

        $pipeline->resolve($request);
    }
}
