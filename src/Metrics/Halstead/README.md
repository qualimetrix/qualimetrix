# Halstead Metrics

**See [Maintainability/README.md](../Maintainability/README.md)** for the full Halstead metrics documentation.

Halstead metrics are part of the Maintainability category and are closely related to the Maintainability Index.

---

## Quick Reference

**Collector:** `HalsteadCollector`
**Provides:** `halstead.volume`, `halstead.difficulty`, `halstead.effort`, `halstead.bugs`, `halstead.time`
**Level:** Method

### Base Components

- **n1** — Unique operators
- **n2** — Unique operands
- **N1** — Total operators
- **N2** — Total operands

### Derived Metrics

| Metric | Formula |
|--------|---------|
| Volume | Length x log2(Vocabulary) |
| Difficulty | (n1/2) x (N2/n2) |
| Effort | Volume x Difficulty |
| Bugs | Volume / 3000 |
| Time | Effort / 18 |

**Details:** See [Maintainability/README.md](../Maintainability/README.md)
