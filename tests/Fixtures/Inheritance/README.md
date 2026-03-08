# Deep Inheritance Hierarchy Fixture

This fixture set contains classes with a deep inheritance hierarchy for testing Depth of Inheritance Tree (DIT) metrics.

## Inheritance Chain

```
BaseEntity (DIT=0)
  ↓
ChildEntity (DIT=1)
  ↓
GrandChildEntity (DIT=2)
  ↓
GreatGrandChildEntity (DIT=3)
  ↓
VeryDeepEntity (DIT=4) ⚠️ Warning threshold
  ↓
ExtremelyDeepEntity (DIT=5) 🚨 Error threshold
```

## Expected Metrics

| Class                 | DIT | NOC | Inherited Methods | Status     |
| --------------------- | --- | --- | ----------------- | ---------- |
| BaseEntity            | 0   | 1   | 0                 | ✅ OK      |
| ChildEntity           | 1   | 1   | 2                 | ✅ OK      |
| GrandChildEntity      | 2   | 1   | 4                 | ✅ OK      |
| GreatGrandChildEntity | 3   | 1   | 6                 | ✅ OK      |
| VeryDeepEntity        | 4   | 1   | 8                 | ⚠️ Warning |
| ExtremelyDeepEntity   | 5   | 0   | 10                | 🚨 Error   |

## Metrics Glossary

- **DIT (Depth of Inheritance Tree)**: Number of ancestor classes (distance from root)
- **NOC (Number of Children)**: Direct subclasses
- **Inherited Methods**: Methods inherited from all ancestors

## Thresholds (Typical)

- **OK**: DIT 0-3
- **Warning**: DIT 4 (approaching problematic depth)
- **Error**: DIT ≥ 5 (excessive depth, refactoring recommended)

## Design Characteristics

Each level adds:
- One new property
- 1-2 new methods
- Constructor that calls parent constructor

## Usage in Tests

Use this fixture to test:
1. DIT calculation accuracy
2. Threshold-based violation detection
3. Inheritance chain traversal
4. NOC counting
5. Method inheritance counting
