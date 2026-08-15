<?php

declare(strict_types=1);

namespace Qualimetrix\Infrastructure\DependencyInjection\CompilerPass;

use InvalidArgumentException;
use Qualimetrix\Analysis\Run\Contract\FileSetInspectionParticipantInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/** Validates and injects file-set inspection participants in stable ID order. */
final class FileSetInspectionParticipantCompilerPass implements CompilerPassInterface
{
    public const string TAG = 'qmx.analysis.run.file_set_inspection_participant';

    private const string COMPOSITE_SERVICE_ID = 'qmx.analysis.run.file_set_inspection_composite';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(self::COMPOSITE_SERVICE_ID)) {
            throw new InvalidArgumentException(
                'File-set inspection composite service "qmx.analysis.run.file_set_inspection_composite" is not registered.',
            );
        }

        /** @var array<string, array{class: class-string<FileSetInspectionParticipantInterface>, reference: Reference}> $participants */
        $participants = [];

        foreach ($container->findTaggedServiceIds(self::TAG) as $serviceId => $_tags) {
            $class = $this->resolveClass($container, $serviceId);

            if (!is_a($class, FileSetInspectionParticipantInterface::class, true)) {
                throw new InvalidArgumentException(\sprintf(
                    'File-set inspection service "%s" (%s) must implement %s.',
                    $serviceId,
                    $class,
                    FileSetInspectionParticipantInterface::class,
                ));
            }

            $participantId = $class::participantId();
            if (trim($participantId) === '') {
                throw new InvalidArgumentException(\sprintf(
                    'File-set inspection participant %s::participantId() must return a non-empty string.',
                    $class,
                ));
            }

            $producerRuleName = $class::producerRuleName();
            if (trim($producerRuleName) === '') {
                throw new InvalidArgumentException(\sprintf(
                    'File-set inspection participant %s::producerRuleName() must return a non-empty string.',
                    $class,
                ));
            }

            if (isset($participants[$participantId])) {
                throw new InvalidArgumentException(\sprintf(
                    'Duplicate file-set inspection participant id "%s" declared by %s and %s.',
                    $participantId,
                    $participants[$participantId]['class'],
                    $class,
                ));
            }

            $participants[$participantId] = [
                'class' => $class,
                'reference' => new Reference($serviceId),
            ];
        }

        uksort($participants, strcmp(...));

        $container->getDefinition(self::COMPOSITE_SERVICE_ID)->setArgument(
            '$participants',
            array_values(array_column($participants, 'reference')),
        );
    }

    /** @return class-string */
    private function resolveClass(ContainerBuilder $container, string $serviceId): string
    {
        $definition = $container->getDefinition($serviceId);
        $class = $definition->getClass();

        if ($class !== null) {
            $class = $container->getParameterBag()->resolveValue($class);
        }

        if (!\is_string($class) || $class === '' || !class_exists($class)) {
            throw new InvalidArgumentException(\sprintf(
                'File-set inspection service "%s" has no resolvable class.',
                $serviceId,
            ));
        }

        return $class;
    }
}
