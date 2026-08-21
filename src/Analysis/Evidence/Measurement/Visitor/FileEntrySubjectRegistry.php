<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Measurement\Visitor;

use LogicException;
use Qualimetrix\Core\Symbol\DeclarationOrdinal;
use Qualimetrix\Core\Symbol\MetricSubjectCodec;

/**
 * The wire subjects minted while one file is read, addressable by a short id.
 *
 * A finding carries the id at the moment it is reported and is resolved into
 * scalar components later, when the file is long gone. Nothing here knows the
 * traversal: which subject is *current* is a property of where the traversal
 * stands, and stays with the lexical scope.
 *
 * This is the half that moves when the wire grammar moves — what a subject is
 * made of, and when a collision ordinal is written at all.
 */
final class FileEntrySubjectRegistry
{
    private const string FILE = 'file';

    /** @var array<string, array{components: array<string, int|string>, ordinal: DeclarationOrdinal}> */
    private array $subjects = [];

    private int $nextSubject = 0;

    public function reset(): void
    {
        $this->subjects = [];
        $this->nextSubject = 0;
    }

    public function fileSubjectId(): string
    {
        return self::FILE;
    }

    /** @param array<string, int|string> $components */
    public function register(array $components, DeclarationOrdinal $ordinal): string
    {
        $id = 'subject-' . $this->nextSubject++;
        $this->subjects[$id] = ['components' => $components, 'ordinal' => $ordinal];

        return $id;
    }

    /**
     * The ordinal is written only when it is not the first declaration of its
     * identity: an explicit zero would be a second wire form of the same
     * subject.
     *
     * @return array<string, int|string>
     */
    public function componentsFor(string $id): array
    {
        if ($id === self::FILE) {
            return MetricSubjectCodec::encodeFile();
        }

        $subject = $this->subjects[$id] ?? throw new LogicException('Unknown file-entry subject reference');
        $components = $subject['components'];
        if (!$subject['ordinal']->isFirst()) {
            $components['collisionOrdinal'] = $subject['ordinal']->value;
        }

        return $components;
    }
}
