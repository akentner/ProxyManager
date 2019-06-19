<?php

declare(strict_types=1);

namespace ProxyManagerTest\Functional;

use Generator;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ProxyManager\Factory\AccessInterceptorValueHolderFactory;
use ProxyManager\Generator\ClassGenerator;
use ProxyManager\Generator\Util\UniqueIdentifierGenerator;
use ProxyManager\GeneratorStrategy\EvaluatingGeneratorStrategy;
use ProxyManager\Proxy\AccessInterceptorInterface;
use ProxyManager\Proxy\ValueHolderInterface;
use ProxyManager\Proxy\AccessInterceptorValueHolderInterface;
use ProxyManager\ProxyGenerator\AccessInterceptorValueHolderGenerator;
use ProxyManagerTest\Assert;
use ProxyManagerTestAsset\BaseClass;
use ProxyManagerTestAsset\BaseInterface;
use ProxyManagerTestAsset\CallableInterface;
use ProxyManagerTestAsset\ClassWithCounterConstructor;
use ProxyManagerTestAsset\ClassWithDynamicArgumentsMethod;
use ProxyManagerTestAsset\ClassWithMethodWithByRefVariadicFunction;
use ProxyManagerTestAsset\ClassWithMethodWithVariadicFunction;
use ProxyManagerTestAsset\ClassWithParentHint;
use ProxyManagerTestAsset\ClassWithPublicArrayProperty;
use ProxyManagerTestAsset\ClassWithPublicArrayPropertyAccessibleViaMethod;
use ProxyManagerTestAsset\ClassWithPublicProperties;
use ProxyManagerTestAsset\ClassWithSelfHint;
use ProxyManagerTestAsset\EmptyClass;
use ProxyManagerTestAsset\OtherObjectAccessClass;
use ProxyManagerTestAsset\VoidCounter;
use ReflectionClass;
use stdClass;
use function array_values;
use function get_class;
use function random_int;
use function serialize;
use function ucfirst;
use function uniqid;
use function unserialize;

/**
 * Tests for {@see \ProxyManager\ProxyGenerator\LazyLoadingValueHolderGenerator} produced objects
 *
 * @group Functional
 * @coversNothing
 */
final class AccessInterceptorValueHolderFunctionalTest extends TestCase
{
    /**
     * @param mixed[] $params
     * @param mixed   $expectedValue
     *
     * @dataProvider getProxyMethods
     *
     * @psalm-template OriginalClass
     * @psalm-param class-string<OriginalClass> $className
     * @psalm-param OriginalClass $instance
     */
    public function testMethodCalls(string $className, object $instance, string $method, array $params, $expectedValue) : void
    {
        $proxy    = $this->makeProxy($className, $instance);
        $callback = [$proxy, $method];

        self::assertIsCallable($callback);
        self::assertSame($instance, $proxy->getWrappedValueHolderValue());
        self::assertSame($expectedValue, $callback(...array_values($params)));

        $listener = $this->createMock(CallableInterface::class);
        $listener
            ->expects(self::once())
            ->method('__invoke')
            ->with($proxy, $instance, $method, $params, false);

        $proxy->setMethodPrefixInterceptor(
            $method,
            static function (
                AccessInterceptorInterface $proxy,
                object $instance,
                string $method,
                array $params,
                bool & $returnEarly
            ) use ($listener) : void {
                $listener->__invoke($proxy, $instance, $method, $params, $returnEarly);
            }
        );

        self::assertSame($expectedValue, $callback(...array_values($params)));

        $random = uniqid('', true);

        $proxy->setMethodPrefixInterceptor(
            $method,
            static function (
                AccessInterceptorInterface $proxy,
                object $instance,
                string $method,
                array $params,
                bool & $returnEarly
            ) use ($random) : string {
                $returnEarly = true;

                return $random;
            }
        );

        self::assertSame($random, $callback(...array_values($params)));
    }

    /**
     * @param mixed[] $params
     * @param mixed   $expectedValue
     *
     * @dataProvider getProxyMethods
     *
     * @psalm-template OriginalClass
     * @psalm-param class-string<OriginalClass> $className
     * @psalm-param OriginalClass $instance
     */
    public function testMethodCallsWithSuffixListener(
        string $className,
        object $instance,
        string $method,
        array $params,
        $expectedValue
    ) : void {
        $proxy    = $this->makeProxy($className, $instance);
        $callback = [$proxy, $method];

        self::assertIsCallable($callback);

        $listener = $this->createMock(CallableInterface::class);
        $listener
            ->expects(self::once())
            ->method('__invoke')
            ->with($proxy, $instance, $method, $params, $expectedValue, false);

        $proxy->setMethodSuffixInterceptor(
            $method,
            /** @param mixed $returnValue */
            static function (
                AccessInterceptorInterface $proxy,
                object $instance,
                string $method,
                array $params,
                $returnValue,
                bool & $returnEarly
            ) use ($listener) : void {
                $listener->__invoke($proxy, $instance, $method, $params, $returnValue, $returnEarly);
            }
        );

        self::assertSame($expectedValue, $callback(...array_values($params)));

        $random = uniqid('', true);

        $proxy->setMethodSuffixInterceptor(
            $method,
            /** @param mixed $returnValue */
            static function (
                AccessInterceptorInterface $proxy,
                object $instance,
                string $method,
                array $params,
                $returnValue,
                bool & $returnEarly
            ) use ($random) : string {
                $returnEarly = true;

                return $random;
            }
        );

        self::assertSame($random, $callback(...array_values($params)));
    }

    /**
     * @param mixed[] $params
     * @param mixed   $expectedValue
     *
     * @dataProvider getProxyMethods
     *
     * @psalm-template OriginalClass
     * @psalm-param class-string<OriginalClass> $className
     * @psalm-param OriginalClass $instance
     */
    public function testMethodCallsAfterUnSerialization(
        string $className,
        object $instance,
        string $method,
        array $params,
        $expectedValue
    ) : void {
        /** @var AccessInterceptorValueHolderInterface $proxy */
        $proxy    = unserialize(serialize($this->makeProxy($className, $instance)));
        $callback = [$proxy, $method];

        self::assertIsCallable($callback);
        self::assertSame($expectedValue, $callback(...array_values($params)));
        self::assertEquals($instance, $proxy->getWrappedValueHolderValue());
    }

    /**
     * @param mixed[] $params
     * @param mixed   $expectedValue
     *
     * @dataProvider getProxyMethods
     *
     * @psalm-template OriginalClass
     * @psalm-param class-string<OriginalClass> $className
     * @psalm-param OriginalClass $instance
     */
    public function testMethodCallsAfterCloning(
        string $className,
        object $instance,
        string $method,
        array $params,
        $expectedValue
    ) : void {
        $proxy    = $this->makeProxy($className, $instance);
        $cloned   = clone $proxy;
        $callback = [$cloned, $method];

        self::assertIsCallable($callback);
        self::assertNotSame($proxy->getWrappedValueHolderValue(), $cloned->getWrappedValueHolderValue());
        self::assertSame($expectedValue, $callback(...array_values($params)));
        self::assertEquals($instance, $cloned->getWrappedValueHolderValue());
    }

    /**
     * @param mixed $propertyValue
     *
     * @dataProvider getPropertyAccessProxies
     */
    public function testPropertyReadAccess(
        object $instance,
        AccessInterceptorValueHolderInterface $proxy,
        string $publicProperty,
        $propertyValue
    ) : void {
        self::assertSame($propertyValue, $proxy->$publicProperty);
        self::assertEquals($instance, $proxy->getWrappedValueHolderValue());
    }

    /**
     * @dataProvider getPropertyAccessProxies
     */
    public function testPropertyWriteAccess(
        object $instance,
        AccessInterceptorValueHolderInterface $proxy,
        string $publicProperty
    ) : void {
        $newValue               = uniqid('', true);
        $proxy->$publicProperty = $newValue;

        self::assertSame($newValue, $proxy->$publicProperty);

        $wrappedValue = $proxy->getWrappedValueHolderValue();

        self::assertNotNull($wrappedValue);
        self::assertSame($newValue, $wrappedValue->$publicProperty);
    }

    /**
     * @dataProvider getPropertyAccessProxies
     */
    public function testPropertyExistence(
        object $instance,
        AccessInterceptorValueHolderInterface $proxy,
        string $publicProperty
    ) : void {
        self::assertSame(isset($instance->$publicProperty), isset($proxy->$publicProperty));
        self::assertEquals($instance, $proxy->getWrappedValueHolderValue());

        $proxy->getWrappedValueHolderValue()->$publicProperty = null;
        self::assertFalse(isset($proxy->$publicProperty));
    }

    /**
     * @dataProvider getPropertyAccessProxies
     */
    public function testPropertyUnset(
        object $instance,
        AccessInterceptorValueHolderInterface $proxy,
        string $publicProperty
    ) : void {
        $instance = $proxy->getWrappedValueHolderValue() ?: $instance;
        unset($proxy->$publicProperty);

        self::assertFalse(isset($instance->$publicProperty));
        self::assertFalse(isset($proxy->$publicProperty));
    }

    /**
     * Verifies that accessing a public property containing an array behaves like in a normal context
     */
    public function testCanWriteToArrayKeysInPublicProperty() : void
    {
        $instance  = new ClassWithPublicArrayPropertyAccessibleViaMethod();
        $proxy     = $this->makeProxy(ClassWithPublicArrayPropertyAccessibleViaMethod::class, $instance);

        $proxy->arrayProperty['foo'] = 'bar';

        self::assertSame('bar', $proxy->getArrayProperty()['foo']);

        $proxy->arrayProperty = ['tab' => 'taz'];

        self::assertSame(['tab' => 'taz'], $proxy->getArrayProperty());
    }

    /**
     * Verifies that public properties retrieved via `__get` don't get modified in the object state
     */
    public function testWillNotModifyRetrievedPublicProperties() : void
    {
        $instance  = new ClassWithPublicProperties();
        $proxy    = $this->makeProxy(ClassWithPublicProperties::class, $instance);
        $variable = $proxy->property0;

        self::assertByRefVariableValueSame('property0', $variable);

        $variable = 'foo';

        self::assertByRefVariableValueSame('property0', $proxy->property0);
        self::assertByRefVariableValueSame('foo', $variable);
    }

    /**
     * Verifies that public properties references retrieved via `__get` modify in the object state
     */
    public function testWillModifyByRefRetrievedPublicProperties() : void
    {
        $instance  = new ClassWithPublicProperties();
        $proxy    = $this->makeProxy(ClassWithPublicProperties::class, $instance);
        $variable = &$proxy->property0;

        self::assertByRefVariableValueSame('property0', $variable);

        $variable = 'foo';

        self::assertByRefVariableValueSame('foo', $proxy->property0);
        self::assertByRefVariableValueSame('foo', $variable);
    }

    /**
     * @group 115
     * @group 175
     */
    public function testWillBehaveLikeObjectWithNormalConstructor() : void
    {
        $instance = new ClassWithCounterConstructor(10);

        self::assertSame(10, $instance->amount, 'Verifying that test asset works as expected');
        self::assertSame(10, $instance->getAmount(), 'Verifying that test asset works as expected');
        $instance->__construct(3);
        self::assertSame(13, $instance->amount, 'Verifying that test asset works as expected');
        self::assertSame(13, $instance->getAmount(), 'Verifying that test asset works as expected');

        $proxyName = $this->generateProxy(ClassWithCounterConstructor::class);

        $proxy = new $proxyName(15);

        self::assertSame(15, $proxy->amount, 'Verifying that the proxy constructor works as expected');
        self::assertSame(15, $proxy->getAmount(), 'Verifying that the proxy constructor works as expected');
        $proxy->__construct(5);
        self::assertSame(20, $proxy->amount, 'Verifying that the proxy constructor works as expected');
        self::assertSame(20, $proxy->getAmount(), 'Verifying that the proxy constructor works as expected');
    }

    public function testWillForwardVariadicArguments() : void
    {
        $factory      = new AccessInterceptorValueHolderFactory();
        $targetObject = new ClassWithMethodWithVariadicFunction();

        $object = $factory->createProxy(
            $targetObject,
            [
                'bar' => static function () : string {
                    return 'Foo Baz';
                },
            ]
        );

        self::assertNull($object->bar);
        self::assertNull($object->baz);

        $object->foo('Ocramius', 'Malukenho', 'Danizord');
        self::assertSame('Ocramius', $object->bar);
        self::assertSame(['Malukenho', 'Danizord'], Assert::readAttribute($object, 'baz'));
    }

    /**
     * @group 265
     */
    public function testWillForwardVariadicByRefArguments() : void
    {
        $factory      = new AccessInterceptorValueHolderFactory();
        $targetObject = new ClassWithMethodWithByRefVariadicFunction();

        /** @var ClassWithMethodWithByRefVariadicFunction $object */
        $object = $factory->createProxy(
            $targetObject,
            [
                'bar' => static function () : string {
                    return 'Foo Baz';
                },
            ]
        );

        $arguments = ['Ocramius', 'Malukenho', 'Danizord'];

        self::assertSame(
            ['Ocramius', 'changed', 'Danizord'],
            (new ClassWithMethodWithByRefVariadicFunction())->tuz(...$arguments),
            'Verifying that the implementation of the test asset is correct before proceeding'
        );
        self::assertSame(['Ocramius', 'changed', 'Danizord'], $object->tuz(...$arguments));
        self::assertSame(['Ocramius', 'changed', 'Danizord'], $arguments, 'By-ref arguments were changed');
    }

    /**
     * This test documents a known limitation: `func_get_args()` (and similars) don't work in proxied APIs.
     * If you manage to make this test pass, then please do send a patch
     *
     * @group 265
     */
    public function testWillNotForwardDynamicArguments() : void
    {
        $object = $this->makeProxy(ClassWithDynamicArgumentsMethod::class, new ClassWithDynamicArgumentsMethod());

        self::assertSame(['a', 'b'], (new ClassWithDynamicArgumentsMethod())->dynamicArgumentsMethod('a', 'b'));

        $this->expectException(ExpectationFailedException::class);

        self::assertSame(['a', 'b'], $object->dynamicArgumentsMethod('a', 'b'));
    }

    /**
     * Generates a proxy for the given class name, and retrieves its class name
     *
     * @psalm-template OriginalClass
     * @psalm-param class-string<OriginalClass> $parentClassName
     * @psalm-return class-string<OriginalClass>
     * @psalm-suppress MoreSpecificReturnType
     */
    private function generateProxy(string $parentClassName) : string
    {
        $generatedClassName = __NAMESPACE__ . '\\' . UniqueIdentifierGenerator::getIdentifier('Foo');
        $generator          = new AccessInterceptorValueHolderGenerator();
        $generatedClass     = new ClassGenerator($generatedClassName);
        $strategy           = new EvaluatingGeneratorStrategy();

        $generator->generate(new ReflectionClass($parentClassName), $generatedClass);
        $strategy->generate($generatedClass);

        /**
         * @psalm-suppress LessSpecificReturnStatement
         */
        return $generatedClassName;
    }

    /**
     * Generates a list of object | invoked method | parameters | expected result
     *
     * @return string[][]|object[][]|mixed[][]
     */
    public function getProxyMethods() : array
    {
        $selfHintParam = new ClassWithSelfHint();
        $empty         = new EmptyClass();

        return [
            [
                BaseClass::class,
                new BaseClass(),
                'publicMethod',
                [],
                'publicMethodDefault',
            ],
            [
                BaseClass::class,
                new BaseClass(),
                'publicTypeHintedMethod',
                ['param' => new stdClass()],
                'publicTypeHintedMethodDefault',
            ],
            [
                BaseClass::class,
                new BaseClass(),
                'publicByReferenceMethod',
                [],
                'publicByReferenceMethodDefault',
            ],
            [
                BaseInterface::class,
                new BaseClass(),
                'publicMethod',
                [],
                'publicMethodDefault',
            ],
            [
                ClassWithSelfHint::class,
                new ClassWithSelfHint(),
                'selfHintMethod',
                ['parameter' => $selfHintParam],
                $selfHintParam,
            ],
            [
                ClassWithParentHint::class,
                new ClassWithParentHint(),
                'parentHintMethod',
                ['parameter' => $empty],
                $empty,
            ],
        ];
    }

    /**
     * Generates proxies and instances with a public property to feed to the property accessor methods
     *
     * @return array<int, array<int, object|AccessInterceptorValueHolderInterface|string>>
     */
    public function getPropertyAccessProxies() : array
    {
        $instance1  = new BaseClass();
        $instance2  = new BaseClass();
        /** @var AccessInterceptorValueHolderInterface $serialized */
        $serialized = unserialize(serialize($this->makeProxy(BaseClass::class, $instance2)));

        return [
            [
                $instance1,
                $this->makeProxy(BaseClass::class, $instance1),
                'publicProperty',
                'publicPropertyDefault',
            ],
            [
                $instance2,
                $serialized,
                'publicProperty',
                'publicPropertyDefault',
            ],
        ];
    }

    /**
     * @group        276
     * @dataProvider getMethodsThatAccessPropertiesOnOtherObjectsInTheSameScope
     */
    public function testWillInterceptAccessToPropertiesViaFriendClassAccess(
        object $callerObject,
        object $realInstance,
        string $method,
        string $expectedValue,
        string $propertyName
    ) : void {
        $proxy    = $this->makeProxy(get_class($realInstance), $realInstance);
        $listener = $this->createMock(CallableInterface::class);

        $listener
            ->expects(self::once())
            ->method('__invoke')
            ->with($proxy, $realInstance, '__get', ['name' => $propertyName]);

        $proxy->setMethodPrefixInterceptor(
            '__get',
            static function ($proxy, $instance, $method, $params, & $returnEarly) use ($listener) : void {
                $listener->__invoke($proxy, $instance, $method, $params, $returnEarly);
            }
        );

        /** @var callable $accessor */
        $accessor = [$callerObject, $method];

        self::assertSame($expectedValue, $accessor($proxy));
    }

    /**
     * @group        276
     * @dataProvider getMethodsThatAccessPropertiesOnOtherObjectsInTheSameScope
     */
    public function testWillInterceptAccessToPropertiesViaFriendClassAccessEvenIfDeSerialized(
        object $callerObject,
        object $realInstance,
        string $method,
        string $expectedValue,
        string $propertyName
    ) : void {
        /** @var AccessInterceptorValueHolderInterface $proxy */
        $proxy    = unserialize(serialize($this->makeProxy(get_class($realInstance), $realInstance)));
        $listener = $this->createMock(CallableInterface::class);

        $listener
            ->expects(self::once())
            ->method('__invoke')
            ->with($proxy, $realInstance, '__get', ['name' => $propertyName]);

        $proxy->setMethodPrefixInterceptor(
            '__get',
            static function ($proxy, $instance, $method, $params, & $returnEarly) use ($listener) : void {
                $listener->__invoke($proxy, $instance, $method, $params, $returnEarly);
            }
        );

        /** @var callable $accessor */
        $accessor = [$callerObject, $method];

        self::assertSame($expectedValue, $accessor($proxy));
    }

    /**
     * @group        276
     * @dataProvider getMethodsThatAccessPropertiesOnOtherObjectsInTheSameScope
     */
    public function testWillInterceptAccessToPropertiesViaFriendClassAccessEvenIfCloned(
        object $callerObject,
        object $realInstance,
        string $method,
        string $expectedValue,
        string $propertyName
    ) : void {
        $proxy = clone $this->makeProxy(get_class($realInstance), $realInstance);

        $listener = $this->createMock(CallableInterface::class);

        $listener
            ->expects(self::once())
            ->method('__invoke')
            ->with($proxy, $realInstance, '__get', ['name' => $propertyName]);

        $proxy->setMethodPrefixInterceptor(
            '__get',
            static function ($proxy, $instance, $method, $params, & $returnEarly) use ($listener) : void {
                $listener->__invoke($proxy, $instance, $method, $params, $returnEarly);
            }
        );

        /** @var callable $accessor */
        $accessor = [$callerObject, $method];

        self::assertSame($expectedValue, $accessor($proxy));
    }

    public function getMethodsThatAccessPropertiesOnOtherObjectsInTheSameScope() : Generator
    {
        foreach ((new ReflectionClass(OtherObjectAccessClass::class))->getProperties() as $property) {
            $property->setAccessible(true);

            $propertyName  = $property->getName();
            $realInstance  = new OtherObjectAccessClass();
            $expectedValue = uniqid('', true);

            $property->setValue($realInstance, $expectedValue);

            // callee is an actual object
            yield OtherObjectAccessClass::class . '#$' . $propertyName => [
                new OtherObjectAccessClass(),
                $realInstance,
                'get' . ucfirst($propertyName),
                $expectedValue,
                $propertyName,
            ];

            $realInstance  = new OtherObjectAccessClass();
            $expectedValue = uniqid('', true);

            $property->setValue($realInstance, $expectedValue);

            // callee is a proxy (not to be lazy-loaded!)
            yield '(proxy) ' . OtherObjectAccessClass::class . '#$' . $propertyName => [
                $this->makeProxy(OtherObjectAccessClass::class, new OtherObjectAccessClass()),
                $realInstance,
                'get' . ucfirst($propertyName),
                $expectedValue,
                $propertyName,
            ];
        }
    }

    /**
     * @group 327
     */
    public function testWillInterceptAndReturnEarlyOnVoidMethod() : void
    {
        $skip      = random_int(100, 200);
        $addMore   = random_int(201, 300);
        $increment = random_int(301, 400);

        $object = (new AccessInterceptorValueHolderFactory())->createProxy(
            new VoidCounter(),
            [
                'increment' => static function (
                    AccessInterceptorInterface $proxy,
                    VoidCounter $instance,
                    string $method,
                    array $params,
                    ?bool & $returnEarly
                ) use ($skip) : void {
                    if ($skip !== $params['amount']) {
                        return;
                    }

                    $returnEarly = true;
                },
            ],
            [
                'increment' => static function (
                    AccessInterceptorInterface $proxy,
                    VoidCounter $instance,
                    string $method,
                    array $params,
                    ?bool & $returnEarly
                ) use ($addMore) : void {
                    if ($addMore !== $params['amount']) {
                        return;
                    }

                    /** @noinspection IncrementDecrementOperationEquivalentInspection */
                    $instance->counter += 1;
                },
            ]
        );

        $object->increment($skip);
        self::assertSame(0, $object->counter);

        $object->increment($increment);
        self::assertSame($increment, $object->counter);

        $object->increment($addMore);
        self::assertSame($increment + $addMore + 1, $object->counter);
    }

    /**
     * @psalm-template OriginalClass
     * @psalm-param class-string<OriginalClass> $originalClassName
     * @psalm-param OriginalClass $realInstance
     * @psalm-return AccessInterceptorValueHolderInterface<OriginalClass>&ValueHolderInterface<OriginalClass>&OriginalClass
     *
     * @psalm-suppress MixedInferredReturnType
     * @psalm-suppress MoreSpecificReturnType
     */
    private function makeProxy(string $originalClassName, object $realInstance) : AccessInterceptorValueHolderInterface
    {
        $proxyClassName = $this->generateProxy($originalClassName);

        /**
         * @psalm-suppress MixedMethodCall
         * @psalm-suppress MixedReturnStatement
         */
        return $proxyClassName::staticProxyConstructor($realInstance);
    }

    /**
     * @param mixed $expected
     * @param mixed $actual
     */
    private static function assertByRefVariableValueSame($expected, & $actual) : void
    {
        self::assertSame($expected, $actual);
    }
}
