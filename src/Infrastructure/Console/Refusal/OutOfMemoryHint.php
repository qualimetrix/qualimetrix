<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\Console\Refusal;

use Closure;
use Qualimetrix\Analysis\Configuration\Contract\Refusal\ConfigurationOrigin;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class OutOfMemoryHint
{
    private static ?self $registered = null;
    private ?string $reserve;
    private ?RefusalPresenter $presenter = null;
    private ?OutputInterface $output = null;
    private ?string $format = null;
    private ?ConfigurationOrigin $limitSource = null;
    /** @var array<int, Closure(): void> */
    private array $releases = [];
    private int $nextRelease = 0;

    private function __construct(private readonly int $originalErrorReporting)
    {
        $this->reserve = str_repeat(' ', 256 * 1024);
    }

    public static function register(): self
    {
        if (self::$registered !== null) {
            return self::$registered;
        }

        $hint = new self(error_reporting());
        self::$registered = $hint;
        ini_set('display_errors', 'stderr');
        // PHP renders a fatal before shutdown callbacks. The callback replaces
        // only a memory fatal and restores the other E_ERROR diagnostics itself.
        error_reporting(error_reporting() & ~\E_ERROR);
        register_shutdown_function($hint->onShutdown(...));

        return $hint;
    }

    public function answerThrough(RefusalPresenter $presenter, OutputInterface $output, ?string $format): void
    {
        $this->presenter = $presenter;
        $this->output = $output;
        $this->format = $format;
    }

    public function limitSetBy(?ConfigurationOrigin $source): void
    {
        $this->limitSource = $source;
    }

    /**
     * @param Closure(): void $release
     *
     * @return Closure(): void Removes this callback after its target is published.
     */
    public function onExhaustion(Closure $release): Closure
    {
        $id = $this->nextRelease++;
        $this->releases[$id] = $release;

        return function () use ($id): void {
            unset($this->releases[$id]);
        };
    }

    public function unbind(): void
    {
        $this->presenter = null;
        $this->output = null;
        $this->format = null;
        $this->limitSource = null;
        $this->releases = [];
    }

    private function onShutdown(): void
    {
        $error = error_get_last();
        if (!self::isFatalError($error)) {
            return;
        }
        if (!str_starts_with($error['message'], 'Allowed memory size of ')) {
            if (($this->originalErrorReporting & \E_ERROR) !== 0) {
                fwrite(\STDERR, \sprintf("Fatal error: %s in %s on line %d\n", $error['message'], $error['file'], $error['line']));
            }

            return;
        }

        if ($this->reserve === null) {
            return;
        }
        $this->reserve = null;
        $presenter = $this->presenter;
        $output = $this->output;
        $format = $this->format;
        $source = $this->limitSource?->describe() ?? 'php.ini';
        $releases = $this->releases;
        $this->unbind();
        self::releaseTargets($releases);

        $message = \sprintf(
            'Qualimetrix ran out of memory at %s:%d (memory_limit=%s, source=%s); raise --memory-limit or memory_limit in qmx.yaml.',
            $error['file'],
            $error['line'],
            \ini_get('memory_limit'),
            $source,
        );
        if ($presenter !== null && $output !== null) {
            $presenter->resourceExhausted($output, $format, $message);
        } else {
            fwrite(\STDERR, $message . "\n");
        }
        // @qmx-ignore-next-line code-smell.exit -- A memory-fatal shutdown is already terminal; after releasing targets and publishing the exhaustion answer, the process must select the incomplete-analysis exit status.
        exit(ConsoleExitCode::AnalysisIncomplete->value);
    }

    /**
     * @param array{type: int, message: string, file: string, line: int}|null $error
     *
     * @phpstan-assert-if-true !null $error
     */
    private static function isFatalError(?array $error): bool
    {
        return $error !== null && $error['type'] === \E_ERROR;
    }

    /**
     * @param array<int, Closure(): void> $releases
     *
     * @qmx-ignore code-smell.empty-catch -- Exhaustion cleanup is best effort; every remaining target must still be released and the resource-exhaustion answer must survive a release failure.
     */
    private static function releaseTargets(array $releases): void
    {
        foreach ($releases as $release) {
            try {
                $release();
            } catch (Throwable) {
                // A failed target release must not suppress the process outcome.
            }
        }
    }
}
