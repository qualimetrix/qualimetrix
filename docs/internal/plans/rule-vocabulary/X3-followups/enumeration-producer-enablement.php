<?php
declare(strict_types=1);

$root = dirname(__DIR__, 5);
require $root . '/vendor/autoload.php';

use Qualimetrix\Analysis\Finding\Contract\ChannelUniverseInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\HierarchicalRuleOptionsInterface;
use Qualimetrix\Analysis\Finding\Contract\Rule\RuleNameReader;
use Qualimetrix\Analysis\Finding\Contract\RuleExecutionInterface;
use Qualimetrix\Infrastructure\DependencyInjection\ContainerFactory;
use Qualimetrix\Infrastructure\Rule\RuleRegistryInterface;

$container = (new ContainerFactory())->create();
$execution = $container->get(RuleExecutionInterface::class);
$universe = $container->get(ChannelUniverseInterface::class);
$registry = $container->get(RuleRegistryInterface::class);

/** Where does this class (or an ancestor inside src/) mention isEnabled? Token-based. */
function isEnabledSites(string $class, string $root): array {
    $seen = [];
    $rc = new ReflectionClass($class);
    while ($rc !== false) {
        $file = $rc->getFileName();
        if (is_string($file) && str_starts_with($file, $root . '/src')) {
            foreach (PhpToken::tokenize((string) file_get_contents($file)) as $t) {
                if ($t->id === T_STRING && str_starts_with($t->text, 'isEnabled')) {
                    $seen[] = $t->text . '@' . substr($file, strlen($root) + 1) . ':' . $t->line;
                }
            }
        }
        $rc = $rc->getParentClass();
    }
    return $seen;
}

$classByName = [];
foreach ($registry->getClasses() as $ruleClass) {
    $classByName[RuleNameReader::read($ruleClass)] = $ruleClass;
}

$rows = [];
foreach ($execution->allRules() as $meta) {
    $name = $meta->name;
    $ruleClass = $classByName[$name] ?? null;
    $optionsClass = $meta->optionsClass;
    $hier = is_a($optionsClass, HierarchicalRuleOptionsInterface::class, true);
    $sites = $ruleClass === null ? [] : isEnabledSites($ruleClass, $root);
    $rows[] = [
        $name,
        $ruleClass === null ? '-' : substr($ruleClass, strrpos($ruleClass, '\\') + 1),
        substr($optionsClass, strrpos($optionsClass, '\\') + 1),
        $hier ? 'hierarchical' : 'flat',
        $sites === [] ? 'NONE' : (string) count($sites),
        $sites === [] ? '' : $sites[0],
    ];
}
usort($rows, fn($a, $b) => $a[0] <=> $b[0]);

$out = "rule\tclass\toptions\tshape\tisEnabled_sites\tfirst_site\n";
foreach ($rows as $r) { $out .= implode("\t", $r) . "\n"; }
file_put_contents(__DIR__ . '/enumeration-producer-enablement.tsv', $out);

$producers = [];
foreach ($universe->channels() as $ch) {
    $p = $universe->producerOf($ch->code);
    if ($p !== null) { $producers[$p] = true; }
}
$named = array_column($rows, 0);
$classless = array_values(array_diff(array_keys($producers), $named));
sort($classless);

printf("rule metadata rows: %d\n", count($rows));
printf("registry classes: %d\n", count($classByName));
printf("flat: %d, hierarchical: %d\n",
    count(array_filter($rows, fn($r) => $r[3] === 'flat')),
    count(array_filter($rows, fn($r) => $r[3] === 'hierarchical')));
printf("no rule class resolved: %d\n", count(array_filter($rows, fn($r) => $r[1] === '-')));
$none = array_filter($rows, fn($r) => $r[4] === 'NONE');
printf("NEVER mentions isEnabled: %d\n", count($none));
foreach ($none as $r) { printf("  - %s (%s / %s, %s)\n", $r[0], $r[1], $r[2], $r[3]); }
printf("producers with no rule of that name: %d\n", count($classless));
foreach ($classless as $p) { printf("  - %s\n", $p); }
