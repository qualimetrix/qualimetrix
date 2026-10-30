<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Configuration\Loader;

use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationRefusal;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationSource;
use Qualimetrix\Core\FileTarget\FileIdentity;
use Qualimetrix\Core\FileTarget\NativeCall;

/**
 * One file an import entry leads to, already known to be a regular file with
 * an identity of its own, and the name a refusal about it uses. The root
 * configuration file is one too: the target whose `imports` list is resolved
 * is the importing file.
 */
final readonly class ImportTarget
{
    private const string PHAR_SCHEME = 'phar://';

    public function __construct(
        /** `file:<device>:<inode>`, or `preset:<name>` for a built-in preset read from inside the phar, which has no identity. */
        public string $key,
        /** What the loader reads. */
        public string $physicalPath,
        public ConfigurationOrigin $origin,
        /** Its own imports are then named from its real directory. */
        public bool $isSymlink,
    ) {}

    /**
     * Refused before the file is read: a target that cannot be stat'ed or
     * reports inode 0 has no identity to compare, and a directory or a FIFO
     * would fail or block the read.
     *
     * @throws ConfigurationRefusal
     */
    public static function ofFile(string $physicalPath, ConfigurationOrigin $origin): self
    {
        // The warning names no reason: a missing file and an untraversable directory read alike.
        [$stat] = NativeCall::attempt(static fn(): array|false => stat($physicalPath));
        $identity = $stat === false ? null : FileIdentity::fromStat($stat);
        $problem = match (true) {
            $identity === null => 'was not found or is not accessible',
            !$identity->isRegularFile() => 'is not a regular file',
            $identity->inode === 0 => 'reports no file identity (inode 0), so it cannot be told apart from the other files',
            default => null,
        };
        if ($problem !== null || $identity === null) {
            throw ConfigurationRefusal::aboutDocument($origin, \sprintf('%s %s.', ucfirst($origin->describe()), $problem ?? 'was not found or is not accessible'));
        }

        return new self(\sprintf('file:%d:%d', $identity->device, $identity->inode), $physicalPath, $origin, is_link($physicalPath));
    }

    /**
     * A built-in preset: a plain file like any other, except inside the phar,
     * where the stream reports an identity a real file could equal.
     *
     * @throws ConfigurationRefusal
     */
    public static function ofPreset(string $name, string $physicalPath, ConfigurationOrigin $origin): self
    {
        return str_starts_with($physicalPath, self::PHAR_SCHEME)
            ? new self('preset:' . $name, $physicalPath, $origin, false)
            : self::ofFile($physicalPath, $origin);
    }

    /**
     * The directory its own entries are read from: that of the file itself,
     * through a symlink to it, so a configuration linked into a project reads
     * the files beside its target.
     *
     * @throws ConfigurationRefusal
     */
    public function realDirectory(): string
    {
        $real = str_starts_with($this->physicalPath, self::PHAR_SCHEME) ? $this->physicalPath : realpath($this->physicalPath);

        return $real === false
            ? throw ConfigurationRefusal::aboutDocument($this->origin, \sprintf('%s cannot be resolved to a file path.', ucfirst($this->origin->describe())))
            : \dirname($real);
    }

    /**
     * What its own entries are named from: the directory of its name, or its
     * real directory when the file is a symlink or has no path of its own
     * (a built-in preset).
     *
     * @throws ConfigurationRefusal
     */
    public function displayDirectory(): string
    {
        return $this->isSymlink || $this->origin->source() === ConfigurationSource::Preset
            ? $this->realDirectory()
            : \dirname($this->origin->locator() ?? '.');
    }
}
