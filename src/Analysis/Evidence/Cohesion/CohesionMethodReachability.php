<?php

declare(strict_types=1);

namespace Qualimetrix\Analysis\Evidence\Cohesion;

use Qualimetrix\Core\Symbol\ClassNameSpelling;

/** Resolves property reads reached through instance method calls. */
final class CohesionMethodReachability
{
    /** @return list<string> */
    public static function properties(LcomClassData $data, string $methodName): array
    {
        $pending = [$methodName];
        $visited = $properties = [];
        while ($pending !== []) {
            $current = array_pop($pending);
            $key = ClassNameSpelling::fold($current);
            if (isset($visited[$key])) {
                continue;
            }
            $visited[$key] = true;
            foreach ($data->getPropertiesAccessedBy($current) as $property) {
                $properties[$property] = true;
            }
            foreach ($data->callsFrom($current) as $callee) {
                $resolved = $data->declaration()->methodSource($callee)?->originalMethod;
                if ($resolved !== null && $data->hasGraphMethod($resolved) && !$data->isStaticMethod($resolved)) {
                    $pending[] = $resolved;
                }
            }
        }

        return array_keys($properties);
    }
}
