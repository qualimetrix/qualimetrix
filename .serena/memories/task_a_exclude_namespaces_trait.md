# Task A: exclude_namespaces trait — Analysis

## Summary

Three Options classes in `src/Rules/Coupling/` have nearly identical code for `exclude_namespaces` handling and `isNamespaceExcluded()` method. This indicates a refactoring opportunity to extract a shared trait.

## Files with Duplicate Code

### 1. NamespaceInstabilityOptions.php
**File**: `/Users/fractalizer/PhpstormProjects/qualimetrix/src/Rules/Coupling/NamespaceInstabilityOptions.php`

```php
public function __construct(
    public bool $enabled = true,
    public float $maxWarning = 0.8,
    public float $maxError = 0.95,
    public int $minClassCount = 3,
    public array $excludeNamespaces = [],
) {}

// In fromArray() - lines 39-45
$excludeNamespaces = [];
$excludeKey = $config['exclude_namespaces'] ?? $config['excludeNamespaces'] ?? null;
if (\is_string($excludeKey)) {
    $excludeNamespaces = [$excludeKey];
} elseif (\is_array($excludeKey)) {
    $excludeNamespaces = array_values($excludeKey);
}

// Method - lines 61-72
public function isNamespaceExcluded(string $namespace): bool
{
    foreach ($this->excludeNamespaces as $prefix) {
        $prefix = rtrim($prefix, '\\');
        if ($namespace === $prefix || str_starts_with($namespace, $prefix . '\\')) {
            return true;
        }
    }
    return false;
}
```

### 2. NamespaceCboOptions.php
**File**: `/Users/fractalizer/PhpstormProjects/qualimetrix/src/Rules/Coupling/NamespaceCboOptions.php`

Same code structure (lines 39-45, 62-73) with identical `isNamespaceExcluded()` implementation.

### 3. DistanceOptions.php
**File**: `/Users/fractalizer/PhpstormProjects/qualimetrix/src/Rules/Coupling/DistanceOptions.php`

```php
// In fromArray() - lines 62-68
$excludeNamespaces = [];
$excludeKey = $config['exclude_namespaces'] ?? $config['excludeNamespaces'] ?? null;
if (\is_string($excludeKey)) {
    $excludeNamespaces = [$excludeKey];
} elseif (\is_array($excludeKey)) {
    $excludeNamespaces = array_values($excludeKey);
}

// Lines 153-157 in DistanceRule
foreach ($this->options->excludeNamespaces as $excludePrefix) {
    if ($this->namespaceMatchesPrefix($namespace, $excludePrefix)) {
        return false;
    }
}

// DistanceRule has its own method (not in options) - lines 181-190
private function namespaceMatchesPrefix(string $namespace, string $prefix): bool
{
    $prefix = rtrim($prefix, '\\');
    if ($namespace === $prefix) {
        return true;
    }
    return str_starts_with($namespace, $prefix . '\\');
}
```

## Usage in Rules

### InstabilityRule.php
**File**: `/Users/fractalizer/PhpstormProjects/qualimetrix/src/Rules/Coupling/InstabilityRule.php` (line 190)

```php
private function analyzeNamespaceLevel(AnalysisContext $context): array
{
    // ...
    foreach ($context->metrics->all(SymbolType::Namespace_) as $nsInfo) {
        // Skip excluded namespaces
        if ($nsInfo->symbolPath->namespace !== null
            && $namespaceOptions->isNamespaceExcluded($nsInfo->symbolPath->namespace)) {
            continue;
        }
        // ...
    }
}
```

### CboRule.php
**File**: `/Users/fractalizer/PhpstormProjects/qualimetrix/src/Rules/Coupling/CboRule.php` (line 170)

```php
private function analyzeNamespaceLevel(AnalysisContext $context): array
{
    // ...
    foreach ($context->metrics->all(SymbolType::Namespace_) as $nsInfo) {
        // Skip excluded namespaces
        if ($nsInfo->symbolPath->namespace !== null
            && $namespaceOptions->isNamespaceExcluded($nsInfo->symbolPath->namespace)) {
            continue;
        }
        // ...
    }
}
```

### DistanceRule.php
**File**: `/Users/fractalizer/PhpstormProjects/qualimetrix/src/Rules/Coupling/DistanceRule.php` (lines 148-176)

Different approach — method in rule, not in options:

```php
private function shouldAnalyzeNamespace(string $namespace): bool
{
    // Check explicit exclusions first
    foreach ($this->options->excludeNamespaces as $excludePrefix) {
        if ($this->namespaceMatchesPrefix($namespace, $excludePrefix)) {
            return false;
        }
    }
    // ... other logic
}

private function namespaceMatchesPrefix(string $namespace, string $prefix): bool
{
    $prefix = rtrim($prefix, '\\');
    if ($namespace === $prefix) {
        return true;
    }
    return str_starts_with($namespace, $prefix . '\\');
}
```

## Refactoring Opportunity

**Extract trait** with:
1. `excludeNamespaces` property
2. `isNamespaceExcluded(string $namespace): bool` method
3. `fromArray()` parsing logic for both `exclude_namespaces` and `excludeNamespaces` keys

**Three classes that would use it:**
- `NamespaceInstabilityOptions`
- `NamespaceCboOptions`
- `DistanceOptions`

**Dependencies:**
- DistanceRule would simplify: remove its `namespaceMatchesPrefix()` and use options method instead
