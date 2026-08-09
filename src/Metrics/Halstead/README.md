# Halstead Metrics

**See [Maintainability/README.md](../Maintainability/README.md)** for the full Halstead metrics documentation.

Halstead metrics are part of the Maintainability category and are closely related to the Maintainability Index.

---

## Quick Reference

**Collector:** `HalsteadCollector`
**Provides:** `halstead.volume`, `halstead.difficulty`, `halstead.effort`, `halstead.bugs`, `halstead.time`
**Level:** Callable

### Base Components

- **n1** — Unique operators
- **n2** — Unique operands
- **N1** — Total operators
- **N2** — Total operands

### Derived Metrics

| Metric     | Formula                   |
| ---------- | ------------------------- |
| Volume     | Length x log2(Vocabulary) |
| Difficulty | (n1/2) x (N2/n2)          |
| Effort     | Volume x Difficulty       |
| Bugs       | Volume / 3000             |
| Time       | Effort / 18               |

**Details:** See [Maintainability/README.md](../Maintainability/README.md)

> **Note:** Modern PHP callables are interpreted semantically. A first-class
> callable capture (`target(...)`) is distinct from invocation and uses a
> capture operator; it does not add the captured target as an operand. PHP 8.5
> `clone($object, [...])` is the language `clone` operator, not a global
> function call. A promoted-parameter default belongs to its constructor;
> property and class-constant initializers do not create synthetic callable
> metrics.
