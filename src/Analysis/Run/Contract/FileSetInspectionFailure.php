<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Run\Contract;

use InvalidArgumentException;
use Qualimetrix\Core\Path\AbsolutePath;
use RuntimeException;

/** A participant could not read selected input files after discovery. */
final class FileSetInspectionFailure extends RuntimeException
{
    /** @var non-empty-list<array{input: AbsolutePath, message: non-empty-string}> */
    public readonly array $failures;

    /** @param non-empty-list<array{input: AbsolutePath, message: non-empty-string}> $failures */
    public function __construct(array $failures)
    {
        if ($failures === [] || !array_is_list($failures)) {
            throw new InvalidArgumentException('File-set inspection failures must be a non-empty list.');
        }

        foreach ($failures as $failure) {
            if (!\is_array($failure)
                || \count($failure) !== 2
                || !isset($failure['input'], $failure['message'])
                || !$failure['input'] instanceof AbsolutePath
                || !\is_string($failure['message'])
                || trim($failure['message']) === '') {
                throw new InvalidArgumentException('Each file-set inspection failure needs an absolute input and a non-empty message.');
            }
        }

        $this->failures = $failures;
        parent::__construct('File-set inspection could not read selected input files.');
    }
}
