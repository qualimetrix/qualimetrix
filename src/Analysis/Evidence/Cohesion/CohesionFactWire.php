<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Cohesion;

use LogicException;

/** Encodes and restores exact class declaration facts between collection phases. */
final class CohesionFactWire
{
    /** @return list<array<string, scalar>> */
    public static function rows(LcomClassData|TccLccClassData $data): array
    {
        return $data instanceof LcomClassData ? self::lcomRows($data) : self::tccRows($data);
    }

    /** @return list<array<string, scalar>> */
    public static function lcomRows(LcomClassData $data): array
    {
        $state = $data->factState();
        $rows = [self::declarationRow($data)];
        foreach ($state['methods'] as $method => $_) {
            $rows[] = [
                'tag' => 'method',
                'name' => $method,
                'static' => isset($state['staticMethods'][$method]),
                'stateless' => isset($state['statelessMethods'][$method]),
            ];
        }
        foreach (CohesionMemberWire::rows($state['declaredMethods'], $state['traitUses'], $state['instancePropertyCount']) as $row) {
            $rows[] = $row;
        }
        foreach (self::propertyRows($state['propertyAccesses']) as $row) {
            $rows[] = $row;
        }
        foreach (self::propertyRows($state['deferredPropertyAccesses'], CohesionMemberWire::DEFERRED_PROPERTY) as $row) {
            $rows[] = $row;
        }
        foreach (['call' => $state['methodCalls'], CohesionMemberWire::DEFERRED_CALL => $state['deferredMethodCalls'], CohesionMemberWire::REFERENCE => $state['methodReferences']] as $tag => $calls) {
            foreach ($calls as $caller => $callees) {
                foreach ($callees as $callee => $_) {
                    $rows[] = ['tag' => $tag, 'caller' => $caller, 'callee' => $callee];
                }
            }
        }

        $rows[0]['rowCount'] = \count($rows);

        return $rows;
    }

    /** @return list<array<string, scalar>> */
    public static function tccRows(TccLccClassData $data): array
    {
        $state = $data->factState();
        $header = self::declarationRow($data);
        $header['propertyCount'] = $state['propertyCount'];
        $rows = [$header];
        foreach ($state['methods'] as $method => $_) {
            $rows[] = ['tag' => 'method', 'name' => $method];
        }
        foreach (self::propertyRows($state['propertyAccesses']) as $row) {
            $rows[] = $row;
        }

        $rows[0]['rowCount'] = \count($rows);

        return $rows;
    }

    /** @return array<string, scalar> */
    private static function declarationRow(LcomClassData|TccLccClassData $data): array
    {
        return [
            'tag' => 'declaration',
            'namespace' => $data->namespace ?? '',
            'name' => $data->className,
            'line' => $data->line,
            'position' => $data->startFilePos,
        ];
    }

    /**
     * @param array<string, array<string, true>> $accesses
     *
     * @return list<array<string, scalar>>
     */
    private static function propertyRows(array $accesses, string $tag = 'property'): array
    {
        $rows = [];
        foreach ($accesses as $method => $properties) {
            foreach ($properties as $property => $_) {
                $rows[] = ['tag' => $tag, 'method' => $method, 'name' => $property];
            }
        }

        return $rows;
    }

    /** @param list<array<string, scalar>> $rows */
    public static function lcomFromRows(array $rows): LcomClassData
    {
        $declaration = self::declaration($rows, 'LCOM');
        $data = new LcomClassData(
            (string) $declaration['namespace'],
            (string) $declaration['name'],
            (int) $declaration['line'],
            (int) $declaration['position'],
        );
        $traitRows = [];
        foreach (\array_slice($rows, 1) as $row) {
            if (\in_array($row['tag'] ?? null, ['trait', 'alias', 'precedence'], true)) {
                $traitRows[] = $row;
                continue;
            }
            self::addLcomRow($data, $row);
        }
        foreach (CohesionTraitUses::fromRows($traitRows) as $use) {
            $data->declaration()->addTraitUse($use);
        }

        return $data;
    }

    /** @param list<array<string, scalar>> $rows */
    public static function tccFromRows(array $rows): TccLccClassData
    {
        $declaration = self::declaration($rows, 'TCC');
        $data = new TccLccClassData(
            (string) $declaration['namespace'],
            (string) $declaration['name'],
            (int) $declaration['line'],
            (int) $declaration['position'],
        );
        $data->incrementPropertyCount((int) $declaration['propertyCount']);
        foreach (\array_slice($rows, 1) as $row) {
            self::addTccRow($data, $row);
        }

        return $data;
    }

    /**
     * @param list<array<string, scalar>> $rows
     *
     * @return array<string, scalar>
     */
    private static function declaration(array $rows, string $metric): array
    {
        $declaration = $rows[0] ?? throw new LogicException($metric . ' declaration facts are missing');
        if (($declaration['tag'] ?? null) !== 'declaration') {
            throw new LogicException($metric . ' declaration facts must begin with a declaration row');
        }
        if (($declaration['rowCount'] ?? null) !== \count($rows)) {
            throw new LogicException($metric . ' declaration facts are incomplete');
        }

        return $declaration;
    }

    /** @param array<string, scalar> $row */
    private static function addLcomRow(LcomClassData $data, array $row): void
    {
        switch ($row['tag'] ?? null) {
            case 'method':
                self::addLcomMethod($data, $row);
                break;
            case 'property':
                $data->addPropertyAccess((string) $row['method'], (string) $row['name']);
                break;
            case 'call':
                $data->addMethodCall((string) $row['caller'], (string) $row['callee']);
                break;
            default:
                CohesionMemberWire::addRow($data, $row);
        }
    }

    /** @param array<string, scalar> $row */
    private static function addLcomMethod(LcomClassData $data, array $row): void
    {
        $method = (string) $row['name'];
        $static = $row['static'] ?? null;
        $stateless = $row['stateless'] ?? null;
        if (!\is_bool($static) || !\is_bool($stateless)) {
            throw new LogicException('LCOM method fact must contain boolean static and stateless flags');
        }
        $data->addMethod($method);
        if ($static) {
            $data->markStatic($method);
        }
        if ($stateless) {
            $data->markStatelessConstant($method);
        }
    }

    /** @param array<string, scalar> $row */
    private static function addTccRow(TccLccClassData $data, array $row): void
    {
        switch ($row['tag'] ?? null) {
            case 'method':
                $data->addMethod((string) $row['name']);
                break;
            case 'property':
                $data->addPropertyAccess((string) $row['method'], (string) $row['name']);
                break;
            default:
                throw new LogicException('Unknown TCC fact row');
        }
    }

}
