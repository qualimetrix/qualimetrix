# T05: LCOM Stateless Method Grouping

**Proposal:** #2 | **Priority:** Batch 2 (false positive reduction) | **Effort:** ~4h | **Dependencies:** none

## Motivation

~70 false-positive LCOM violations caused by interface-mandated methods like `getName()`,
`getDescription()`, `priority()` that return constants and share no state. These inflate LCOM4
because each stateless method forms its own connected component.

## Design

**Decision:** Use stateless-method heuristic, not interface resolution. Methods that don't access
instance state and return constants are effectively metadata — grouping them into one component
is correct regardless of whether they come from an interface.

**Algorithm change in `LcomClassData::calculateLcom()`:**

**Step 1 — Classify methods as stateless (single pass, no iteration needed):**

A method is "stateless constant" if ALL of the following hold:
- No `$this->property` access (empty `propertyAccesses` for this method)
- No `$this->method()` calls at all (empty `methodCalls` for this method), OR all called
  methods are themselves stateless (but to avoid iterative resolution, use the simpler rule:
  no property access AND return-only body)
- Body is a single return of: scalar literal, class constant (`self::X`, `static::X`),
  array of literals/constants, `null`, `true`, `false`

**Simplification:** Do NOT attempt transitive statefulness resolution. A method that calls
`$this->getName()` (even if getName is stateless) is connected to getName via the existing
method-call edge — BFS will naturally group them. The classification only needs to identify
leaf stateless methods.

**Step 2 — Merge stateless methods into one virtual node:**

Replace all stateless methods with a single virtual node `__stateless__` in the adjacency graph:
- Remove all stateless method names from the vertex set
- Add one `__stateless__` vertex
- Redirect all edges from/to stateless methods to `__stateless__`:
  - If a stateful method M calls `$this->getName()` (stateless), add edge M ↔ `__stateless__`
  - If a stateless method calls `$this->otherStateless()`, this becomes an internal edge
    within `__stateless__` (collapsed, no effect on component count)

**Step 3 — BFS as before** on the modified graph.

**Effect:** If a class has 3 stateless methods and 2 disconnected stateful groups:
- Before: 5 components (3 stateless + 2 stateful)
- After: 3 components (1 virtual + 2 stateful)
- If a stateful method calls a stateless one: 2 components (virtual merges with that stateful group)

### Extended trivial detection

Current "trivial" detection (`LcomClassData` lines 211-244) only covers:
- Empty body
- Single return of null/scalar/true/false/empty array

Extend to also recognize:
- `return self::NAME;` / `return static::NAME;` — class constant return
- `return [self::KEY => 'value', ...];` — array of constants
- `return new self(...)` — factory-like (still stateless if args are literals)

## Files to modify

| File                                         | Change                                               |
| -------------------------------------------- | ---------------------------------------------------- |
| `src/Metrics/Structure/LcomClassData.php`    | Add stateless method detection + grouping logic      |
| `src/Metrics/Structure/LcomVisitor.php`      | Extend trivial method detection for constant returns |
| `src/Metrics/Structure/LcomCollector.php`    | No change needed (uses LcomClassData output)         |
| Tests for LCOM                               | Add test cases for stateless grouping                |
| `src/Metrics/README.md`                      | Document the heuristic                               |
| Website: LCOM metric documentation (EN + RU) | Document deviation with `!!! info` block             |

## Acceptance criteria

- [ ] Class with `getName(): string { return 'foo'; }` + `getDescription()` + `analyze()` + `validate()` — LCOM=2 (not 4)
- [ ] Class with only stateless methods — LCOM=1
- [ ] Class with one stateless method + two disconnected stateful groups — LCOM=3 (1+2)
- [ ] Methods that call `$this->getName()` (stateless) still form edges to stateless group
- [ ] `return self::NAME` recognized as stateless
- [ ] `return $this->config->get('key')` NOT recognized as stateless (accesses instance state via method call)
- [ ] Existing trivial class exemption still works (all trivial → LCOM=1)
- [ ] Golden file test updated with new expected values
- [ ] PHPStan passes, tests pass

## Edge cases

- Stateless method that calls another stateless method → both in same group (correct: they'd be connected anyway)
- Method with `$this->property` access but returns constant → NOT stateless (has property access)
- Static method → already excluded from LCOM, no change needed
- Method with match/switch returning different constants → IS stateless (no property access, returns constant)
- Method with `$this->method()` where method is stateless → the calling method is connected to stateless group
- Class with 0 stateless and 0 stateful methods → LCOM=0 (unchanged)

## Implementation notes

**Where statefulness data lives:**
- `LcomVisitor` (AST traversal) already tracks property accesses and method calls per method
- `LcomClassData` stores this in `$propertyAccesses` and `$methodCalls` maps
- New: add `isStatelessConstant(string $method): bool` to `LcomClassData`
- The classification logic (checking return-only body with constant value) belongs in `LcomVisitor`
  during AST traversal, stored as a set `$statelessMethods` in `LcomClassData`

## Risk

This changes LCOM4 values for many classes. The golden file test (`GoldenFileAggregationTest`) will
need updated expected values. Run `bin/qmx check src/` before and after to verify the change
reduces false positives without hiding real cohesion issues.

Also run `composer benchmark:check` — LCOM changes may shift health scores enough to require
`composer benchmark:update` for recalibration.
