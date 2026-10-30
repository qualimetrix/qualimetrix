<?php

declare(strict_types=1);

namespace Qualimetrix\Tests\Analysis\Run\Unit\Configuration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Qualimetrix\Analysis\Configuration\ConfigurationRoot;
use Qualimetrix\Analysis\Configuration\Contract\ConfigurationDocument;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationPipelineInterface;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationResolutionRequest;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Analysis\Configuration\Document\AuthoredLayer;
use Qualimetrix\Analysis\Configuration\Document\AuthoredNode;
use Qualimetrix\Analysis\Configuration\Document\DocumentComposer;
use Qualimetrix\Analysis\Configuration\Document\DocumentSchema;
use Qualimetrix\Analysis\Configuration\Preset\PresetResolver;
use Qualimetrix\Analysis\Run\Configuration\InstalledConfigurationSources;
use Qualimetrix\Analysis\Run\Configuration\ProjectScopeCoverage;
use Qualimetrix\Analysis\Run\Configuration\RunConfigurationResolver;
use Qualimetrix\Analysis\Run\Contract\Configuration\AutoloadDevPolicy;
use Qualimetrix\Analysis\Run\Contract\Configuration\PathsAuthorship;
use Qualimetrix\Core\FileTarget\FileIdentity;
use Qualimetrix\Core\Path\AbsolutePath;
use Qualimetrix\Infrastructure\Composer\ComposerManifestReader;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use ReflectionClass;

/**
 * Which file sources of a real configuration document lie in the directory Composer
 * installs into. The document is the product pipeline's, the facts the manifest reader's.
 */
#[CoversClass(InstalledConfigurationSources::class)]
#[CoversClass(ProjectScopeCoverage::class)]
#[CoversClass(RunConfigurationResolver::class)]
final class InstalledConfigurationSourcesTest extends TestCase
{
    private const string BASE = 'vendor/acme/std/base.yaml';
    private const string BASE_NAME = 'configuration file "vendor/acme/std/base.yaml" (imported by configuration file "qmx.yaml")';

    private string $dir;
    private string $project;

    protected function setUp(): void
    {
        $this->dir = (string) realpath(sys_get_temp_dir()) . '/qmx-installed-sources-' . bin2hex(random_bytes(8));
        $this->project = $this->dir . '/project';
        mkdir($this->project, 0o777, true);
        file_put_contents($this->project . '/composer.json', '{}');
    }

    protected function tearDown(): void
    {
        self::remove($this->dir);
    }

    #[Test]
    public function itRecognisesAnImportFromTheInstallDirectory(): void
    {
        $this->put('project/' . self::BASE, "exclude: [{subtree: legacy}]\n");
        $this->put('project/qmx.yaml', "imports: [" . self::BASE . "]\n");

        self::assertSame([self::BASE_NAME], $this->installed());
    }

    #[Test]
    public function itDoesNotRecogniseAnImportFromTheProject(): void
    {
        $this->put('project/config/base.yaml', "exclude: [{subtree: legacy}]\n");
        $this->put('project/qmx.yaml', "imports: [config/base.yaml]\n");
        mkdir($this->project . '/vendor');

        self::assertSame([], $this->installed());
    }

    #[Test]
    public function itAnswersAlikeForASymlinkedPathRepositoryAndItsMirroredCopy(): void
    {
        $this->put('project/qmx.yaml', "imports: [" . self::BASE . "]\n");
        $this->put('packages/std/base.yaml', "exclude: [{subtree: legacy}]\n");
        mkdir($this->project . '/vendor/acme', 0o777, true);
        symlink('../../../packages/std', $this->project . '/vendor/acme/std');
        $linked = $this->installed();

        unlink($this->project . '/vendor/acme/std');
        mkdir($this->project . '/vendor/acme/std');
        copy($this->dir . '/packages/std/base.yaml', $this->project . '/' . self::BASE);
        $mirrored = $this->installed();

        self::assertSame([self::BASE_NAME], $linked);
        self::assertSame($linked, $mirrored);
    }

    #[Test]
    public function itFollowsAnInstallDirectoryThatIsItselfALink(): void
    {
        $this->put('elsewhere/acme/std/base.yaml', "exclude: [{subtree: legacy}]\n");
        symlink('../elsewhere', $this->project . '/vendor');
        $this->put('project/qmx.yaml', "imports: [" . self::BASE . "]\n");

        self::assertSame([self::BASE_NAME], $this->installed());
    }

    #[Test]
    public function itRecognisesAnImportSpelledThroughAProjectDirectoryAndBackIntoTheInstallDirectory(): void
    {
        $this->put('project/' . self::BASE, "exclude: [{subtree: legacy}]\n");
        mkdir($this->project . '/config');
        $this->put('project/qmx.yaml', "imports: [config/../" . self::BASE . "]\n");

        self::assertSame(['configuration file "config/../vendor/acme/std/base.yaml" (imported by configuration file "qmx.yaml")'], $this->installed());
    }

    #[Test]
    public function itRefusesAnImportThatLeavesTheInstallDirectoryThroughDotDot(): void
    {
        $this->put('project/' . self::BASE, "exclude: [{subtree: other}]\n");
        $this->put('project/config/x.yaml', "exclude: [{subtree: legacy}]\n");
        $this->put('project/qmx.yaml', "imports: [vendor/acme/std/../../../config/x.yaml, " . self::BASE . "]\n");

        self::assertSame([self::BASE_NAME], $this->installed());
    }

    #[Test]
    public function itFollowsAConfiguredRelativeInstallDirectory(): void
    {
        $this->put('project/composer.json', '{"config": {"vendor-dir": "lib/deps"}}');
        $this->put('project/lib/deps/acme/std/base.yaml', "exclude: [{subtree: legacy}]\n");
        $this->put('project/qmx.yaml', "imports: [lib/deps/acme/std/base.yaml]\n");

        self::assertSame(['configuration file "lib/deps/acme/std/base.yaml" (imported by configuration file "qmx.yaml")'], $this->installed());
    }

    #[Test]
    public function itFollowsAnAbsoluteInstallDirectoryOutsideTheProject(): void
    {
        $this->put('project/composer.json', \sprintf('{"config": {"vendor-dir": %s}}', json_encode($this->dir . '/abs-vendor', \JSON_THROW_ON_ERROR)));
        $this->put('abs-vendor/acme/std/base.yaml', "exclude: [{subtree: legacy}]\n");
        $this->put('project/qmx.yaml', "imports: [../abs-vendor/acme/std/base.yaml]\n");

        self::assertSame(['configuration file "../abs-vendor/acme/std/base.yaml" (imported by configuration file "qmx.yaml")'], $this->installed());
    }

    /** @return iterable<string, array{string}> */
    public static function provideInstallDirectoriesThatEstablishNothing(): iterable
    {
        yield 'the project root' => ['.'];
        yield 'above the project root' => ['..'];
        yield 'a directory spelled with a home shortcut Composer would expand' => ['~/x'];
        yield 'a directory that does not exist' => ['missing'];
    }

    #[Test]
    #[DataProvider('provideInstallDirectoriesThatEstablishNothing')]
    public function itEstablishesNothingFromAnInstallDirectoryThatCannotBeTrusted(string $vendorDir): void
    {
        $this->put('project/composer.json', \sprintf('{"config": {"vendor-dir": %s}}', json_encode($vendorDir, \JSON_THROW_ON_ERROR)));
        $this->put('project/' . self::BASE, "exclude: [{subtree: legacy}]\n");
        $this->put('project/qmx.yaml', "imports: [" . self::BASE . "]\n");

        self::assertSame([], $this->installed());
    }

    #[Test]
    public function itEstablishesNothingWithoutAReadableManifest(): void
    {
        $this->put('project/' . self::BASE, "exclude: [{subtree: legacy}]\n");
        $this->put('project/qmx.yaml', "imports: [" . self::BASE . "]\n");
        self::assertSame([self::BASE_NAME], $this->installed(), 'control: a valid manifest');

        unlink($this->project . '/composer.json');
        self::assertSame([], $this->installed(), 'absent composer.json');

        $this->put('project/composer.json', '{not json');
        self::assertSame([], $this->installed(), 'invalid composer.json');
    }

    #[Test]
    public function itEstablishesNothingFromAnUnreadableManifest(): void
    {
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('A superuser reads any file.');
        }
        $this->put('project/' . self::BASE, "exclude: [{subtree: legacy}]\n");
        $this->put('project/qmx.yaml', "imports: [" . self::BASE . "]\n");
        $this->put('project/composer.json', '{}');
        chmod($this->project . '/composer.json', 0o000);

        try {
            self::assertSame([], $this->installed());
        } finally {
            chmod($this->project . '/composer.json', 0o644);
        }
    }

    #[Test]
    public function itRecognisesAPresetFileFromTheInstallDirectory(): void
    {
        $this->put('project/vendor/acme/std/preset.yaml', "exclude: [{subtree: legacy}]\n");

        self::assertSame(['preset "vendor/acme/std/preset.yaml"'], $this->installed(presets: ['vendor/acme/std/preset.yaml']));
    }

    #[Test]
    public function itNeverRecognisesABuiltInPresetEvenBesideAnInstalledImport(): void
    {
        $this->put('project/' . self::BASE, "exclude: [{subtree: legacy}]\n");
        $this->put('project/qmx.yaml', "imports: [" . self::BASE . "]\n");

        self::assertSame([self::BASE_NAME], $this->installed(presets: ['strict']));
    }

    #[Test]
    public function itNeverRecognisesABuiltInPresetReachedThroughAnInstalledImporter(): void
    {
        $this->put('project/' . self::BASE, '');
        $importer = ConfigurationOrigin::of(ConfigurationSource::ConfigFile, self::BASE);
        $origin = ConfigurationOrigin::of(ConfigurationSource::Preset, 'strict')->importedThrough($importer);

        $document = $this->handBuilt($origin, null, 'unused');

        self::assertSame([], InstalledConfigurationSources::of($document, (new ComposerManifestReader())->read(AbsolutePath::fromString($this->project))));
    }

    #[Test]
    public function itNeverRecognisesACommandLineOption(): void
    {
        $this->put('project/' . self::BASE, "exclude: [{subtree: legacy}]\n");
        $this->put('project/qmx.yaml', "imports: [" . self::BASE . "]\n");

        $installed = $this->installed(cli: ['exclude' => [['subtree' => 'build']]], cliNames: ['exclude' => '--exclude']);

        self::assertSame([self::BASE_NAME], $installed);
    }

    #[Test]
    public function itRecognisesOneInstalledFileUsedAsAPresetAndAsAnImport(): void
    {
        $this->put('project/' . self::BASE, "exclude: [{subtree: legacy}]\n");
        $this->put('project/qmx.yaml', "imports: [" . self::BASE . "]\n");

        $installed = $this->installed(presets: [self::BASE]);

        sort($installed);
        self::assertSame([self::BASE_NAME, 'preset "' . self::BASE . '"'], $installed);
    }

    #[Test]
    public function itDoesNotRecogniseALocatorThatLeadsToAnotherFileTheConfigurationWasReadFrom(): void
    {
        $this->put('project/' . self::BASE, '');
        $origin = ConfigurationOrigin::of(ConfigurationSource::ConfigFile, self::BASE);
        $identity = FileIdentity::ofRegularFile(stat($this->project . '/' . self::BASE));
        \assert($identity !== null);

        $document = $this->handBuilt($origin, $identity, 'configuration file "elsewhere.yaml"');

        self::assertSame([], InstalledConfigurationSources::of($document, (new ComposerManifestReader())->read(AbsolutePath::fromString($this->project))));
    }

    #[Test]
    public function itRecognisesAFileTheConfigurationWasReadFromUnderItsOwnName(): void
    {
        $this->put('project/' . self::BASE, '');
        $origin = ConfigurationOrigin::of(ConfigurationSource::ConfigFile, self::BASE);
        $identity = FileIdentity::ofRegularFile(stat($this->project . '/' . self::BASE));
        \assert($identity !== null);

        $document = $this->handBuilt($origin, $identity, $origin->describe());

        self::assertSame([$origin->describe()], InstalledConfigurationSources::of($document, (new ComposerManifestReader())->read(AbsolutePath::fromString($this->project))));
    }

    #[Test]
    public function itRecognisesTheRootConfigurationWhenItIsItselfInstalled(): void
    {
        $this->put('project/vendor/acme/std/qmx.yaml', "exclude: [{subtree: legacy}]\n");

        $installed = $this->installed(config: $this->project . '/vendor/acme/std/qmx.yaml');

        self::assertSame(['configuration file "' . $this->project . '/vendor/acme/std/qmx.yaml"'], $installed);
    }

    #[Test]
    public function itSeesTheRootAnImportAndAPresetFileOfARealDocument(): void
    {
        $this->put('project/vendor/acme/std/base.yaml', "exclude: [{subtree: imported}]\n");
        $this->put('project/vendor/acme/std/preset.yaml', "suppress_paths: [{subtree: preset}]\n");
        $this->put('project/qmx.yaml', "imports: [vendor/acme/std/base.yaml]\nexclude: [{subtree: root}]\n");

        $installed = $this->installed(presets: ['vendor/acme/std/preset.yaml']);

        sort($installed);
        self::assertSame([self::BASE_NAME, 'preset "vendor/acme/std/preset.yaml"'], $installed);
    }

    #[Test]
    public function itKeepsTheBuiltInPresetsFromWritingAnyAccumulatingSet(): void
    {
        $directory = (new ReflectionClass(PresetResolver::class))->getFileName();
        self::assertIsString($directory);
        $presets = glob(\dirname($directory) . '/*.yaml');
        self::assertNotEmpty($presets);

        foreach ($presets as $preset) {
            $document = (string) file_get_contents($preset);
            foreach (['exclude', 'suppress_paths', 'suppress_namespaces'] as $key) {
                self::assertDoesNotMatchRegularExpression('/^' . $key . '\s*:/m', $document, basename($preset) . ' writes ' . $key . ': every consumer would inherit an entry it cannot remove');
            }
        }
    }

    #[Test]
    public function itReachesTheMeasuredUniverseThroughTheResolver(): void
    {
        $this->put('project/' . self::BASE, "exclude: [{subtree: legacy}]\n");
        $this->put('project/qmx.yaml', "imports: [" . self::BASE . "]\n");
        $root = AbsolutePath::fromString($this->project);
        $document = self::pipeline()->resolve(new ConfigurationResolutionRequest($root));
        $coverage = new ProjectScopeCoverage(new ComposerManifestReader());

        $resolved = (new RunConfigurationResolver($coverage))->resolve($document);
        $withoutDocument = $coverage->measure($root, [$root], AutoloadDevPolicy::Exclude, PathsAuthorship::Inferred);

        self::assertSame([self::BASE_NAME], $resolved->projectScope->universe->installedConfigurationSources);
        self::assertSame([], $withoutDocument->universe->installedConfigurationSources);
    }

    /**
     * @param list<string> $presets
     * @param array<string, mixed> $cli
     * @param array<string, string> $cliNames
     *
     * @return list<string>
     */
    private function installed(array $presets = [], array $cli = [], array $cliNames = [], ?string $config = null): array
    {
        $root = AbsolutePath::fromString($this->project);
        $document = self::pipeline()->resolve(new ConfigurationResolutionRequest($root, $config, $presets, $cli, $cliNames));

        return InstalledConfigurationSources::of($document, (new ComposerManifestReader())->read($root));
    }

    private static function pipeline(): ConfigurationPipelineInterface
    {
        static $pipeline = null;
        if ($pipeline === null) {
            $pipeline = (new ContainerFactory())->create()->get(ConfigurationPipelineInterface::class);
            \assert($pipeline instanceof ConfigurationPipelineInterface);
        }

        return $pipeline;
    }

    private function handBuilt(ConfigurationOrigin $origin, ?FileIdentity $read, string $name): ConfigurationDocument
    {
        $layer = new AuthoredLayer($origin, AuthoredNode::fromPlain(['exclude' => [['subtree' => 'legacy']]]));
        $resolved = DocumentComposer::compose(new DocumentSchema(ConfigurationRoot::cases()), [$layer]);

        return new ConfigurationDocument(
            $read === null ? [] : [['source' => 'x', 'values' => [], 'file' => $read, 'name' => $name]],
            AbsolutePath::fromString($this->project),
            $resolved,
        );
    }

    private function put(string $relative, string $content): void
    {
        $path = $this->dir . '/' . $relative;
        if (!is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0o777, true);
        }
        file_put_contents($path, $content);
    }

    private static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        $entries = scandir($path);
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::remove($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
