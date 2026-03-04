# Circular Dependencies Fixture

This fixture set contains PHP classes with circular dependencies for testing coupling metrics and cycle detection.

## Structure

### Circular Dependency Chain (Length 3)
```
ServiceA → ServiceB → ServiceC → ServiceA
```

- **ServiceA**: Depends on ServiceB, depended by ServiceC
- **ServiceB**: Depends on ServiceC, depended by ServiceA
- **ServiceC**: Depends on ServiceA, depended by ServiceB (completes the cycle)

### Simple Bidirectional Dependency (Length 2)
```
HelperA ⇄ HelperB
```

- **HelperA**: Depends on HelperB, depended by HelperB
- **HelperB**: Depends on HelperA, depended by HelperA

### Control (No Dependencies)
- **IndependentService**: No dependencies, isolated class

## Expected Metrics

### ServiceA, ServiceB, ServiceC
- **Ca**: 1 (one class depends on each)
- **Ce**: 1 (each depends on one class)
- **Instability**: 0.5 (Ce / (Ca + Ce) = 1 / 2)
- **Cycle Detection**: Part of 3-node cycle

### HelperA, HelperB
- **Ca**: 1
- **Ce**: 1
- **Instability**: 0.5
- **Cycle Detection**: Part of 2-node cycle

### IndependentService
- **Ca**: 0
- **Ce**: 0
- **Instability**: N/A
- **Cycle Detection**: No cycles

## Usage in Tests

Use this fixture to test:
1. Circular dependency detection algorithms
2. Coupling metrics (Ca, Ce, Instability)
3. Cycle length calculation
4. Isolated class handling (control group)
