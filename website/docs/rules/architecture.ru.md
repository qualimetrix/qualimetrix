# Правила архитектуры (Architecture)

Правила архитектуры выявляют структурные проблемы в кодовой базе, которые могут привести к кошмарам при поддержке. Эти проблемы часто незаметны в повседневной работе, но причиняют значительную боль, когда нужно провести рефакторинг, протестировать или развернуть части приложения независимо.

---

## Циклические зависимости (Circular Dependencies)

**Идентификатор правила:** `architecture.circular-dependency`

<!-- llms:skip-begin -->
### Что измеряет

Обнаруживает ситуации, когда классы зависят друг от друга по кругу. Зависимость означает, что один класс использует другой (через внедрение в конструктор, вызовы методов, указания типов и т.д.).

**Прямой цикл (размер 2):**

```
OrderService --> PaymentService --> OrderService
```

OrderService использует PaymentService, а PaymentService использует OrderService. Ни один из них не может существовать без другого.

**Транзитивный цикл (размер 3+):**

```
A --> B --> C --> A
```

A зависит от B, B зависит от C, а C зависит обратно от A. Петля длиннее, но проблема та же.

<!-- llms:skip-end -->

### Почему это важно

Циклические зависимости вызывают реальные проблемы:

- **Невозможно тестировать изолированно.** Чтобы протестировать класс A, нужен класс B, которому нужен класс C, которому снова нужен A.
- **Невозможно развертывать независимо.** Если пакеты A, B и C образуют цикл, они должны всегда развертываться вместе.
- **Жесткая связанность.** Изменения в любом классе цикла могут сломать все остальные классы в цикле.
- **Труднее понять.** Нет четкого "верха" или "низа" -- нельзя читать код в линейном порядке.

### Пороговые значения

| Тип цикла                | Серьезность | Значение                                     |
| ------------------------ | ----------- | -------------------------------------------- |
| Прямой (размер 2)        | Error       | Два класса напрямую зависят друг от друга    |
| Транзитивный (размер 3+) | Warning     | Более длинная цепочка классов образует петлю |

!!! note "Примечание"
    Прямые циклы (A зависит от B, B зависит от A) по умолчанию отмечаются как **Error**, потому что они представляют наиболее жесткую связанность. Транзитивные циклы отмечаются как **Warning**, так как их обычно легче разорвать.

### Настройки

| Опция           | По умолчанию | Описание                                               |
| --------------- | ------------ | ------------------------------------------------------ |
| `enabled`       | `true`       | Включить или выключить правило                         |
| `maxCycleSize`  | `0`          | Максимальный размер цикла для отчета (0 = все размеры) |
| `directAsError` | `true`       | Считать прямые циклы (размер 2) ошибками               |

### Пример конфигурации

```yaml
# aimd.yaml
rules:
  architecture.circular-dependency:
    maxCycleSize: 5        # игнорировать очень большие циклы
    directAsError: true    # прямые циклы -- ошибки
```

<!-- llms:skip-begin -->
### Пример

```php
// OrderService.php
class OrderService
{
    public function __construct(
        private PaymentService $paymentService,  // зависит от PaymentService
    ) {}

    public function createOrder(Cart $cart): Order
    {
        $order = new Order($cart);
        $this->paymentService->charge($order);
        return $order;
    }

    public function getOrderTotal(int $orderId): float
    {
        // ...
        return $total;
    }
}

// PaymentService.php
class PaymentService
{
    public function __construct(
        private OrderService $orderService,  // зависит от OrderService -- ЦИКЛ!
    ) {}

    public function charge(Order $order): void
    {
        $total = $this->orderService->getOrderTotal($order->id);
        // обработка платежа...
    }
}
```

`OrderService` зависит от `PaymentService`, а `PaymentService` зависит от `OrderService`. Это прямой цикл размера 2.

<!-- llms:skip-end -->

<!-- llms:skip-begin -->
### Как исправить

1. **Введите интерфейс (инверсия зависимостей).** Пусть один класс зависит от абстракции, а не от конкретного класса:

    ```php
    interface OrderTotalProviderInterface
    {
        public function getOrderTotal(int $orderId): float;
    }

    class OrderService implements OrderTotalProviderInterface
    {
        public function __construct(
            private PaymentService $paymentService,
        ) {}

        public function getOrderTotal(int $orderId): float { /* ... */ }
    }

    class PaymentService
    {
        public function __construct(
            private OrderTotalProviderInterface $totalProvider,  // нет цикла!
        ) {}
    }
    ```

2. **Вынесите общую логику в третий класс.** Если обоим классам нужны одни и те же данные, извлеките их:

    ```php
    class OrderRepository
    {
        public function getTotal(int $orderId): float { /* ... */ }
    }

    // Оба сервиса зависят от OrderRepository, а не друг от друга
    ```

3. **Используйте события.** Вместо прямых вызовов генерируйте событие, на которое подписывается другой сервис:

    ```php
    class OrderService
    {
        public function createOrder(Cart $cart): Order
        {
            $order = new Order($cart);
            $this->eventDispatcher->dispatch(new OrderCreated($order));
            return $order;
        }
    }

    // PaymentService подписан на OrderCreated -- нет прямой зависимости
    ```

!!! tip "Совет"
    Используйте опцию `maxCycleSize`, чтобы сначала сосредоточиться на самых критичных циклах. Прямые циклы (размер 2) легче всего исправить и они наиболее вредны. Начните с них, затем переходите к более крупным циклам.

<!-- llms:skip-end -->
