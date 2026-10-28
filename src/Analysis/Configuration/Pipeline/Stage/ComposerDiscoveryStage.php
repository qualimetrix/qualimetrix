<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Pipeline\Stage;

use Qualimetrix\Analysis\Configuration\ConfigSchema;
use Qualimetrix\Analysis\Configuration\Contract\Pipeline\ConfigurationResolutionRequest;
use Qualimetrix\Analysis\Configuration\Pipeline\ConfigurationLayer;
use Qualimetrix\Analysis\Configuration\Pipeline\ConfigurationStageInterface;
use Qualimetrix\Analysis\ProjectManifest\Contract\ComposerManifestFacts;
use Qualimetrix\Analysis\ProjectManifest\Contract\ComposerManifestReaderInterface;
use Qualimetrix\Analysis\ProjectManifest\Contract\ManifestReadState;
use Qualimetrix\Core\FileTarget\FileIdentity;
use Qualimetrix\Core\FileTarget\NativeCall;

/**
 * Auto-discovers paths from composer.json autoload (priority: 10).
 *
 * Contributes the production and the `autoload-dev` targets as two lists,
 * not as `paths`: whether test code is part of the project is
 * `include_autoload_dev`, which a source after this one may write, so the run
 * configuration picks the default analysis paths from both lists. The lists
 * are the reader's whole-manifest answer, the one
 * {@see \Qualimetrix\Analysis\Run\Configuration\ProjectScopeCoverage}
 * judges scope against, so a run over the defaults covers what it is judged
 * against whatever autoload form declared the code.
 *
 * A manifest that was read is a source even when it declares no targets: its
 * identity is how a written target is refused as the manifest.
 */
final class ComposerDiscoveryStage implements ConfigurationStageInterface
{
    private const int PRIORITY = 10;

    public function __construct(
        private readonly ComposerManifestReaderInterface $composerReader,
    ) {}

    public function priority(): int
    {
        return self::PRIORITY;
    }

    public function name(): string
    {
        return 'composer';
    }

    public function apply(ConfigurationResolutionRequest $request): ?ConfigurationLayer
    {
        $facts = $this->composerReader->read($request->workingDirectory);
        $production = $facts->productionTargets();
        $development = $facts->developmentTargets();
        $file = self::identityOfRead($facts);

        if ($production === [] && $development === []) {
            return $file === null ? null : new ConfigurationLayer('composer.json', file: $file);
        }

        return new ConfigurationLayer('composer.json', [
            ConfigSchema::DISCOVERED_AUTOLOAD_PATHS => $production,
            ConfigSchema::DISCOVERED_AUTOLOAD_DEV_PATHS => $development,
        ], file: $file);
    }

    /**
     * The manifest is stated just after the invocation's only read of it, by
     * path: every later reader is served that snapshot, not the file.
     */
    private static function identityOfRead(ComposerManifestFacts $facts): ?FileIdentity
    {
        if ($facts->state !== ManifestReadState::Read && $facts->state !== ManifestReadState::Invalid) {
            return null;
        }
        $source = $facts->source();
        [$stat] = NativeCall::attempt(static fn(): array|false => stat($source));
        $identity = \is_array($stat) ? FileIdentity::fromStat($stat) : null;

        return $identity === null || $identity->inode === 0 || !$identity->isRegularFile() ? null : $identity;
    }
}
