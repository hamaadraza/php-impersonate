<?php

declare(strict_types=1);

namespace Raza\PHPImpersonate\Tests;

use ReflectionMethod;
use PHPUnit\Framework\TestCase;
use Raza\PHPImpersonate\PHPImpersonate;
use PHPUnit\Framework\Attributes\DataProvider;
use Raza\PHPImpersonate\PHPImpersonateFactory;

/**
 * The deprecated factory, which had no tests at all.
 *
 * Its whole contract is that it forwards unchanged — it used to repeat the
 * bodies of PHPImpersonate's static helpers, which meant two copies of the same
 * defaults quietly drifting apart. That is the property worth testing, and it
 * can be tested without a single network request: compare the two signatures.
 */
class PHPImpersonateFactoryTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function forwardedMethodProvider(): array
    {
        return [
            'get' => ['get'],
            'post' => ['post'],
            'head' => ['head'],
            'delete' => ['delete'],
            'patch' => ['patch'],
            'put' => ['put'],
        ];
    }

    #[DataProvider('forwardedMethodProvider')]
    public function testSignatureMatchesPHPImpersonate(string $method): void
    {
        $factory = new ReflectionMethod(PHPImpersonateFactory::class, $method);
        $target = new ReflectionMethod(PHPImpersonate::class, $method);

        $this->assertSame(
            $this->describe($target),
            $this->describe($factory),
            "PHPImpersonateFactory::$method has drifted from PHPImpersonate::$method"
        );
    }

    /**
     * Every forwarding method must exist on both sides — a method added to one
     * and not the other is the same drift by another route.
     */
    public function testTheTwoClassesExposeTheSameStaticHelpers(): void
    {
        $names = static function (string $class): array {
            $out = [];
            foreach ((new \ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
                if ($m->isStatic() && $m->getDeclaringClass()->getName() === $class) {
                    $out[] = $m->getName();
                }
            }
            sort($out);

            return $out;
        };

        // PHPImpersonate carries extras the factory never mirrored (ffiAvailable,
        // closeFfiEngines), so the factory's set must be a subset of it.
        $this->assertSame(
            [],
            array_diff($names(PHPImpersonateFactory::class), $names(PHPImpersonate::class)),
            'the factory exposes a static helper PHPImpersonate does not'
        );
    }

    public function testFactoryIsMarkedDeprecated(): void
    {
        $doc = (string) (new \ReflectionClass(PHPImpersonateFactory::class))->getDocComment();

        $this->assertStringContainsString('@deprecated', $doc);
    }

    /**
     * Parameter names, order, types and default values — the whole shape a
     * caller depends on.
     */
    private function describe(ReflectionMethod $method): string
    {
        $parts = [];

        foreach ($method->getParameters() as $parameter) {
            $default = 'none';
            if ($parameter->isDefaultValueAvailable()) {
                $default = var_export($parameter->getDefaultValue(), true);
            }

            $parts[] = sprintf(
                '%s $%s = %s',
                (string) $parameter->getType(),
                $parameter->getName(),
                $default
            );
        }

        return (string) $method->getReturnType() . ' (' . implode(', ', $parts) . ')';
    }
}
