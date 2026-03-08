# Metric Aggregation Fixture

This fixture set contains classes with documented cyclomatic complexity for testing metric aggregation across all hierarchy levels.

## Structure

```
App/
├── Service/
│   ├── UserService (3 methods, CCN sum=10)
│   ├── OrderService (2 methods, CCN sum=9)
│   └── PaymentService (2 methods, CCN sum=10)
└── Repository/
    ├── UserRepository (4 methods, CCN sum=7)
    └── OrderRepository (3 methods, CCN sum=7)
```

## Method-Level Metrics

### App\Service\UserService
| Method          | CCN    | Description                  |
| --------------- | ------ | ---------------------------- |
| findById()      | 2      | Simple if check              |
| findByEmail()   | 3      | if + foreach                 |
| create()        | 5      | Multiple conditions and loop |
| **Class Total** | **10** | **sum=10, max=5, avg=3.33**  |

### App\Service\OrderService
| Method          | CCN   | Description                                          |
| --------------- | ----- | ---------------------------------------------------- |
| validate()      | 1     | No branches                                          |
| process()       | 8     | Complex branching (if + foreach + nested conditions) |
| **Class Total** | **9** | **sum=9, max=8, avg=4.5**                            |

### App\Service\PaymentService
| Method          | CCN    | Description                |
| --------------- | ------ | -------------------------- |
| authorize()     | 4      | Three if checks            |
| charge()        | 6      | if + switch with 3 cases   |
| **Class Total** | **10** | **sum=10, max=6, avg=5.0** |

### App\Repository\UserRepository
| Method          | CCN   | Description                |
| --------------- | ----- | -------------------------- |
| findAll()       | 1     | No branches                |
| findOne()       | 1     | No branches                |
| save()          | 2     | Single if                  |
| delete()        | 3     | Two if checks              |
| **Class Total** | **7** | **sum=7, max=3, avg=1.75** |

### App\Repository\OrderRepository
| Method          | CCN   | Description                |
| --------------- | ----- | -------------------------- |
| findByUser()    | 2     | foreach loop               |
| findByStatus()  | 3     | foreach + nested if        |
| updateStatus()  | 2     | Single if                  |
| **Class Total** | **7** | **sum=7, max=3, avg=2.33** |

## Class-Level Aggregation

| Class           | Methods | CCN Sum | CCN Max | CCN Avg |
| --------------- | ------- | ------- | ------- | ------- |
| UserService     | 3       | 10      | 5       | 3.33    |
| OrderService    | 2       | 9       | 8       | 4.5     |
| PaymentService  | 2       | 10      | 6       | 5.0     |
| UserRepository  | 4       | 7       | 3       | 1.75    |
| OrderRepository | 3       | 7       | 3       | 2.33    |

## Namespace-Level Aggregation

### App\Service
- **Classes**: 3 (UserService, OrderService, PaymentService)
- **CCN Sum**: 29 (10 + 9 + 10)
- **CCN Max**: 10 (max of class sums)
- **Methods**: 7 (3 + 2 + 2)
- **Class Count**: 3

### App\Repository
- **Classes**: 2 (UserRepository, OrderRepository)
- **CCN Sum**: 14 (7 + 7)
- **CCN Max**: 7 (max of class sums)
- **Methods**: 7 (4 + 3)
- **Class Count**: 2

### App (parent namespace)
- **Classes**: 5 (all classes)
- **CCN Sum**: 43 (29 + 14)
- **CCN Max**: 10 (max of all class sums)
- **Methods**: 14 (7 + 7)
- **Class Count**: 5

## Project-Level Aggregation

- **CCN Sum**: 43 (sum of all class CCN sums)
- **CCN Max**: 10 (max of all class sums)
- **Methods**: 14 (total method count)
- **Classes**: 5 (total class count)

## Violation Scenarios

With typical thresholds:
- **Method threshold = 4**: Violations in create(5), process(8), charge(6)
- **Class threshold = 8**: Violations in UserService(10), OrderService(9), PaymentService(10)

## Usage in Tests

Use this fixture to verify:
1. ✅ Method-level CCN calculation
2. ✅ Class-level aggregation (sum, max, avg)
3. ✅ Namespace-level aggregation (hierarchical)
4. ✅ Project-level aggregation (global)
5. ✅ Rule violation detection
6. ✅ Multi-namespace handling
7. ✅ Exit code generation based on violations
