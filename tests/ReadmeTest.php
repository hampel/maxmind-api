<?php

declare(strict_types=1);

namespace Hampel\MaxMind\Api\Tests;

use Hampel\MaxMind\Api\Client;
use Hampel\MaxMind\Api\Exception\ExceptionInterface;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * The README is documentation for people who cannot read the source, which makes a wrong example
 * worse than a missing one: it is copied, it fails, and the reader concludes the package is broken.
 * Renaming a method is exactly the change that passes every other test here - the suite calls the
 * new name and only the README still calls the old one.
 *
 * So this checks that everything the README names exists. It cannot check the examples are
 * correct; it can check they are not stale, which is the failure that actually happens.
 */
final class ReadmeTest extends BaseTestCase
{
    private const NS = 'Hampel\\MaxMind\\Api\\';

    private function readme(): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/README.md');
    }

    public function testEveryDocumentedEndpointCallExists(): void
    {
        preg_match_all('/\$(?:maxmind|sandbox)->(\w+)\(\)->(\w+)\(/', $this->readme(), $matches, PREG_SET_ORDER);

        $this->assertGreaterThan(4, count($matches));

        foreach ($matches as [, $accessor, $method]) {
            $this->assertTrue(method_exists(Client::class, $accessor), sprintf('the README calls ->%s(), which Client does not have', $accessor));

            $returns = (string) (new \ReflectionMethod(Client::class, $accessor))->getReturnType();

            $this->assertTrue(method_exists($returns, $method), sprintf('the README calls ->%s()->%s(), which %s does not have', $accessor, $method, $returns));
        }
    }

    public function testEveryDocumentedClientMethodExists(): void
    {
        preg_match_all('/(?:\$maxmind->|\$sandbox->|Client::)(\w+)\(/', $this->readme(), $matches);

        $this->assertNotEmpty($matches[1]);

        foreach (array_unique($matches[1]) as $method) {
            $this->assertTrue(method_exists(Client::class, $method), sprintf('the README calls %s() on Client, which does not exist', $method));
        }
    }

    public function testEveryImportedClassAndStaticCallExists(): void
    {
        $readme = $this->readme();

        preg_match_all('/^use (Hampel\\\\MaxMind\\\\Api\\\\[\w\\\\]+);$/m', $readme, $uses);

        $this->assertNotEmpty($uses[1]);

        $imported = [];

        foreach ($uses[1] as $class) {
            $this->assertTrue(class_exists($class) || interface_exists($class), sprintf('the README imports %s, which does not exist', $class));
            $imported[substr((string) strrchr($class, '\\'), 1)] = $class;
        }

        preg_match_all('/\b([A-Z]\w+)::(\w+)\b/', $readme, $statics, PREG_SET_ORDER);

        foreach ($statics as [, $short, $member]) {
            $class = $imported[$short] ?? null;

            if ($class === null || $member === 'class') {
                continue;
            }

            $this->assertTrue(method_exists($class, $member) || defined($class . '::' . $member), sprintf('the README names %s::%s, which does not exist', $short, $member));
        }
    }

    public function testEveryNamedModelExists(): void
    {
        preg_match_all('/`Model\\\\(\w+)`/', $this->readme(), $matches);

        $this->assertNotEmpty($matches[1]);

        foreach (array_unique($matches[1]) as $name) {
            $this->assertTrue(class_exists(self::NS . 'Model\\' . $name), sprintf('the README names Model\\%s, which does not exist', $name));
        }
    }

    /**
     * Each variable the README reads from, and what it holds. A chain - `$insights->anonymizer->x`
     * - is followed through the declared property types.
     */
    public function testEveryDocumentedPropertyOrMethodExists(): void
    {
        $receivers = [
            '$country' => self::NS . 'Model\\Country',
            '$city' => self::NS . 'Model\\City',
            '$insights' => self::NS . 'Model\\Insights',
            '$anonymizer' => self::NS . 'Record\\Anonymizer',
            '$reader' => self::NS . 'Database\\Reader',
            '$downloads' => self::NS . 'Endpoint\\Downloads',
            '$download' => self::NS . 'Download\\Download',
            '$e' => self::NS . 'Exception\\ApiException',
        ];

        $checked = 0;

        foreach ($receivers as $variable => $class) {
            preg_match_all('/' . preg_quote($variable, '/') . '((?:->\w+(?:\([^)]*\))?)+)/', $this->readme(), $matches);

            foreach ($matches[1] as $chain) {
                preg_match_all('/->(\w+)(\()?/', $chain, $links, PREG_SET_ORDER);
                $current = $class;

                foreach ($links as $link) {
                    $name = $link[1];
                    $isCall = isset($link[2]);

                    $this->assertTrue(
                        $isCall ? method_exists($current, $name) : property_exists($current, $name),
                        sprintf('the README uses %s%s, and %s has no %s%s', $variable, $chain, $current, $name, $isCall ? '()' : '')
                    );

                    $type = $isCall
                        ? (new \ReflectionMethod($current, $name))->getReturnType()
                        : (new \ReflectionProperty($current, $name))->getType();
                    $current = $type instanceof \ReflectionNamedType ? ltrim($type->getName(), '?') : 'mixed';
                    $checked++;

                    if (!class_exists($current)) {
                        break;
                    }
                }
            }
        }

        $this->assertGreaterThan(30, $checked);
    }

    public function testEveryDocumentedExceptionExistsAndIsOurs(): void
    {
        preg_match_all('/`(\w+Exception|ExceptionInterface)`/', $this->readme(), $matches);

        $this->assertGreaterThan(8, count(array_unique($matches[1])));

        foreach (array_unique($matches[1]) as $name) {
            $class = self::NS . 'Exception\\' . $name;

            $this->assertTrue(class_exists($class) || interface_exists($class), sprintf('the README names %s, which does not exist', $name));
            $this->assertTrue($class === ExceptionInterface::class || is_subclass_of($class, ExceptionInterface::class));
        }
    }
}
