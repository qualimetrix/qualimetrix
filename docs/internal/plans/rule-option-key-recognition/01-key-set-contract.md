# 01 — The key set becomes a declaration

## Goal

Make "which keys may be written here" a fact the product can ask for, at both
depths, instead of a fact it reconstructs from constructor reflection plus two
partial opt-in interfaces.

## Why today's answer is wrong rather than incomplete

`RuleOptionsFactory::warnAboutUnknownKeys()` builds its known-key set from
three sources (`RuleOptionsFactory.php:360-414`):

1. constructor parameter names, via `ReflectionClass` (`extractDefaults()`);
2. `ShorthandOptionKeysInterface::getShorthandOptionKeys()`;
3. `AdditionalOptionKeysInterface::getAdditionalOptionKeys()`.

All three describe the class from the outside. `fromArray()` is a method body,
and reflection cannot see into it — which is exactly what
`measurement/option-declared-vs-read.tsv` measures: six classes read keys none
of the three sources mention, and `ShorthandOptionKeysInterface`'s own docblock
already admits the gap it was written to patch. Two partial declarations beside
a body that is the real authority is one source too few and one too many.

There is a decided precedent for the shape of the answer. ADR 0038 replaced a
property-name guess in `BaselineConfiguredThresholds` with
`ThresholdAwareOptionsInterface::warningBoundary()`: *a class that holds a
warning boundary says which one it is, and the reader asks*. The same sentence
with a different noun is this stage.

## The contract

Three additions and two removals, all inside
`src/Analysis/Finding/Contract/Rule/`.

### `RuleOptionKeySet` — a value, not a list

```php
final readonly class RuleOptionKeySet
{
    /** @param list<string> $accepted canonical kebab spellings */
    public static function of(string ...$accepted): self;

    /**
     * Keys the class recognises only in order to refuse them in its own
     * words, or to accept a spelling that means "leave things as they are".
     * The factory neither warns nor refuses these: it lets fromArray() speak.
     */
    public function alsoAnsweredByTheClass(string ...$keys): self;

    /** True when $key — already normalised — is in either half. */
    public function knows(string $key): bool;

    /** Canonical kebab spellings, sorted, for the "allowed here" sentence. */
    public function acceptedForDisplay(): array;

    // ... implementation details
}
```

Three states, not two, and the third is forced by Fact 3 of the overview: a key
the class recognises and answers about itself. Collapsing it into *accepted*
would silence `UnassignedClassOptions`' refusal; collapsing it into *unknown*
would print the generic sentence one line above the specific one, which is the
defect being removed. The set is closed over both halves, so a key is in
exactly one of three states, and the factory branches on that.

Kebab is the declared spelling because it is the one users type
(`docs/internal/CLI_CONVENTIONS.md`), and it is the only spelling the "allowed
here" sentence prints. Comparison always happens after
`ConfigKeySpelling::normalize()` on both sides, so snake, camel and kebab stay
equivalent on input — enumeration row 61, which is today true and undocumented,
becomes true and stated.

### `RuleOptionsInterface` gains one static

```php
public static function acceptedOptionKeys(): RuleOptionKeySet;
```

Static, for the same reason `getShorthandOptionKeys()` is: the factory consults
it before any instance exists.

### `LevelOptionsInterface` gains the same static

A slot answers for itself. This is the half the current design has no place for
at all, and Fact 2 is why it cannot be answered by the parent: `callable` and
`class` on the same `ComplexityOptions` accept disjoint threshold keys
(`measurement/option-level-slots.tsv`, column `keys_allowed_inside_each_slot`,
with the `file:line` of each set).

### `HierarchicalRuleOptionsInterface` gains a slot map

```php
/** @return array<string, class-string<LevelOptionsInterface>> slot name => level options class */
public static function levelOptionsClasses(): array;
```

Slot names are `SymbolLevel` values, so `callable`, `class`, `namespace` — the
strings the user writes and ADR 0024 settled. This restates the slot list that
`HierarchicalRuleOptionsInterface::getSupportedLevels()` already carries, which
is the two-sources shape this stage removes for keys. The accepted resolution
is the cheaper direction: `getSupportedLevels()` becomes derived from
`levelOptionsClasses()` (its keys, mapped back through `SymbolLevel::from()`)
wherever a class does not override it, so the static is the single statement and
the instance method is a view of it. Where a class must override, a pinning test
asserts the two agree; that is the accepted cost and it is named rather than
left to be found. The map is declared rather than
derived from constructor parameter types, for two reasons: deriving it is the
guess ADR 0038 retired, and the derivation would be wrong anyway — the slot
`callable` is held by a parameter named `callable` whose type is
`MethodComplexityOptions`, and nothing in the tree makes those agree.

Five classes implement it: `ComplexityOptions`, `CognitiveComplexityOptions`,
`NpathComplexityOptions`, `CboOptions`, `InstabilityOptions` — the `yes` rows
of `measurement/option-level-slots.tsv`.

### `ShorthandOptionKeysInterface` and `AdditionalOptionKeysInterface` are deleted — in stage 03, not here

Both are partial statements of what `acceptedOptionKeys()` now states in full,
and keeping them beside it is two sources for one fact. But they are **not**
deleted in this stage, because their last reader is the factory and the factory
is rewritten in stage 03. Deleting them earlier is not inert: `RuleOptionsFactory`
imports both (`:12`, `:15`) and probes them with `is_a()` in
`acceptedExtraOptionKeysFor()` (`:426-439`), and `is_a()` against a class string
that no longer exists returns `false` in silence — so `$acceptedExtraKeys` would
become empty and every configuration writing a bare `threshold:` would start
warning "Unknown option". PHPStan would redden on the two `use` lines; the
runtime would not.

The references were enumerated rather than estimated —
`grep -rn 'ShorthandOptionKeysInterface\|AdditionalOptionKeysInterface' src tests scripts --include=*.php -l`
returns **23 files**: 20 production Options classes across Complexity,
Coupling, CodeSmell, Cohesion, Design, Duplication, Maintainability and Size,
plus `RuleOptionsFactory` itself, plus
`tests/Analysis/Finding/Unit/RuleOptionsFactoryTest.php`,
`tests/Analysis/Policy/Architecture/Unit/UnassignedClassOptionsTest.php` and
`scripts/enumerate-rule-option-keys.php`. Two further docblock mentions carry no
import (`ThresholdParser.php:27`, `LongParameterListOptions.php:32`). Those 20
classes implement *both* the old interfaces and the new method through stages 02
and 03, and shed the old ones in П3.1.

Breaking; there are no external implementers by policy, and the migration is
mechanical (`CHANGELOG.md`, stage 04).

## Who owns this contract, and why

`Analysis\Finding\Contract\Rule`. Checked against ADR 0016's three tests rather
than asserted:

- **Naming.** "This directory is about the contracts by which a rule and its
  options are authored." A statement of which option keys a rule accepts
  completes that sentence without naming a role or a base class. `Contract/Rule`
  already holds `RuleOptionKey`, `ThresholdParser`, `ThresholdAwareOptionsInterface`,
  `LevelOptionsInterface` and the two interfaces stage 03 retires.
- **Co-change.** A rule gaining an option changes its own capability's Options
  class and nothing here; the contract changes only when the *shape* of an
  option declaration changes. That is one directory plus its implementers,
  which is the expected shape for a contract.
- **Duplication.** If the tree were decomposed fully by subject, this would not
  be copied into every capability — it would move wholesale into Finding, which
  is where it is.

Direction of dependency is unchanged and already proven: the eight Evidence
capabilities and two Policy capabilities already import
`Analysis\Finding\Contract\Rule\*` in every Options class
(`measurement/option-declared-vs-read.tsv` lists all 35). Finding does not
import them back. `Core` is wrong for this: the type has a natural leaf owner
and many imports do not make it neutral (ADR 0022).

The consumer is `Analysis\Finding\RuleConfiguration\RuleOptionsFactory`, which
is Finding-internal, so the contract's only cross-owner surface is the
implementing side — which is what a rule-authoring contract is for.

## Work packages

**П1.1 — the contract (single package, nothing parallel).**

Files:

- `src/Analysis/Finding/Contract/Rule/RuleOptionKeySet.php` (new)
- `src/Analysis/Finding/Contract/Rule/RuleOptionsInterface.php`
- `src/Analysis/Finding/Contract/Rule/LevelOptionsInterface.php`
- `src/Analysis/Finding/Contract/Rule/HierarchicalRuleOptionsInterface.php`
- `src/Analysis/Finding/README.md`
- `tests/Analysis/Finding/…` — unit tests for `RuleOptionKeySet` only

Depends on: nothing. Parallel with: nothing.

## What this stage leaves broken

Adding an abstract static to `RuleOptionsInterface` and `LevelOptionsInterface`
breaks every implementer at once — 35 options classes and 10 level classes —
so **the tree does not compile between П1.1 and the end of stage 02.**

This is deliberate and it is the one place in the plan where a package's own
Definition of Done cannot be "the aggregate is green". The alternatives were
weighed:

- A default implementation on a trait or an abstract base would let the tree
  compile and would let a class *silently inherit an empty declaration* — the
  exact failure ADR 0038 named ("a class that acquires a configured boundary
  without joining the interface is caught by a test, not by review attention").
- Making the method optional (a fourth `is_a(...)` probe in the factory) rebuilds
  the partial-declaration design this stage exists to delete.

So the two stages are one landing unit: **П1.1 and all of stage 02 are one
commit series on one branch, and only their union is offered for validation.**
Stage 02's package DoD names the aggregate; П1.1's DoD is `composer cs-check`
plus its own unit tests plus a compile check of the contract files themselves.

Nothing user-visible changes in this stage: the factory still warns, still at
depth 1 only, still from reflection. The new declarations are inert until
stage 03 reads them.

## Test plan (no tests written here)

- `RuleOptionKeySet` unit: three states are disjoint and exhaustive; `knows()`
  answers on normalised input for snake, camel and kebab spellings of the same
  key; `acceptedForDisplay()` is sorted, kebab, and excludes the
  answered-by-the-class half.
- No behavioural test belongs to this stage: there is no behaviour yet, and the
  two old interfaces are still alive and still read by the factory. The test
  that pins their death belongs to stage 04.
