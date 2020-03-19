# DeprecatedProperty

Emitted when getting/setting a deprecated property of a given class

```php
class A {
    /**
     * @deprecated
     * @var ?string
     */
    public $foo;
}
(new A())->foo = 5;
```
