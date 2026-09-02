<?php

declare(strict_types=1);

namespace Raza\PHPImpersonate\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Raza\PHPImpersonate\Platform\CommandBuilder;

/**
 * CommandBuilder builds argv arrays for proc_open()'s array mode, which
 * executes the program directly with no shell involved. The assertions here are
 * therefore about argv structure — flag/value pairing, prefixes, ordering — and
 * emphatically NOT about quoting: values must arrive verbatim, because any
 * escaping applied here would be sent literally to the program.
 */
class CommandBuilderTest extends TestCase
{
    /**
     * Index of $needle in $args, or null when absent.
     *
     * @param list<string> $args
     */
    private function indexOf(array $args, string $needle): ?int
    {
        $index = array_search($needle, $args, true);

        return $index === false ? null : (int) $index;
    }

    /**
     * The value argv carries immediately after $flag.
     *
     * @param list<string> $args
     */
    private function valueAfter(array $args, string $flag): ?string
    {
        $index = $this->indexOf($args, $flag);

        return $index === null ? null : ($args[$index + 1] ?? null);
    }

    // -------------------------------------------------------------------------
    // Basic building
    // -------------------------------------------------------------------------

    public function testBasicGenericCommandBuilding(): void
    {
        $args = CommandBuilder::buildCommandArgs('test-command', ['arg1', 'arg2'], [
            'verbose' => true,
            'output' => 'file.txt',
            'config' => 'config.json',
        ]);

        $this->assertSame('test-command', $args[0]);
        $this->assertContains('--verbose', $args);
        $this->assertSame('file.txt', $this->valueAfter($args, '--output'));
        $this->assertSame('config.json', $this->valueAfter($args, '--config'));

        // Positional arguments are appended last, unquoted and in order.
        $this->assertSame(['arg1', 'arg2'], array_slice($args, -2));
    }

    public function testCurlCommandBuilding(): void
    {
        $args = CommandBuilder::buildCurlCommandArgs('curl', ['https://example.com'], [
            's' => true,
            'L' => true,
            'max-time' => 30,
            'H' => ['Content-Type: application/json', 'Authorization: Bearer token'],
            'data' => '{"key":"value"}',
        ]);

        $this->assertSame('curl', $args[0]);
        $this->assertContains('-s', $args);
        $this->assertContains('-L', $args);
        $this->assertSame('30', $this->valueAfter($args, '--max-time'));
        $this->assertSame('{"key":"value"}', $this->valueAfter($args, '--data'));
        $this->assertSame('https://example.com', end($args));

        $this->assertContains('Content-Type: application/json', $args);
        $this->assertContains('Authorization: Bearer token', $args);
    }

    public function testCurlCommandWithMixedOptionTypes(): void
    {
        $args = CommandBuilder::buildCurlCommandArgs('curl-impersonate', ['https://api.example.com'], [
            's' => true,
            'verbose' => true,
            'w' => '%{http_code}',
            'max-time' => 30,
            'user-agent' => 'TestAgent/1.0',
        ]);

        $this->assertContains('-s', $args);
        $this->assertContains('--verbose', $args);
        $this->assertSame('%{http_code}', $this->valueAfter($args, '-w'));
        $this->assertSame('30', $this->valueAfter($args, '--max-time'));
        $this->assertSame('TestAgent/1.0', $this->valueAfter($args, '--user-agent'));
    }

    // -------------------------------------------------------------------------
    // Option shapes
    // -------------------------------------------------------------------------

    public function testArrayOptionRepeatsTheFlagPerValue(): void
    {
        $headers = ['Content-Type: application/json', 'Authorization: Bearer token123'];

        $args = CommandBuilder::buildCommandArgs('test-command', [], [
            'header' => $headers,
            'config' => ['config1.json', 'config2.json'],
        ]);

        $this->assertSame(2, count(array_keys($args, '--header', true)));
        $this->assertSame(2, count(array_keys($args, '--config', true)));

        foreach ($headers as $header) {
            $this->assertContains($header, $args);
        }
    }

    /**
     * @param array<string,mixed> $options
     * @param list<string> $expectedPresent
     * @param list<string> $expectedAbsent
     */
    #[DataProvider('booleanOptionsProvider')]
    public function testBooleanOptionsHandling(array $options, array $expectedPresent, array $expectedAbsent): void
    {
        $args = CommandBuilder::buildCommandArgs('test-command', [], $options);

        foreach ($expectedPresent as $flag) {
            $this->assertContains("--$flag", $args);
        }
        foreach ($expectedAbsent as $flag) {
            $this->assertNotContains("--$flag", $args);
        }
    }

    /**
     * @return array<string, array{0: array<string,mixed>, 1: list<string>, 2: list<string>}>
     */
    public static function booleanOptionsProvider(): array
    {
        return [
            'mixed options' => [
                ['verbose' => true, 'quiet' => false, 'debug' => true, 'force' => false],
                ['verbose', 'debug'],
                ['quiet', 'force'],
            ],
            'all true options' => [
                ['help' => true, 'version' => true],
                ['help', 'version'],
                [],
            ],
            'all false options' => [
                ['quiet' => false, 'silent' => false],
                [],
                ['quiet', 'silent'],
            ],
        ];
    }

    public function testEmptyAndNullValueHandling(): void
    {
        $args = CommandBuilder::buildCommandArgs('test-command', [null, '', 'valid'], [
            'empty' => '',
            'null' => null,
            'zero' => 0,
            'false' => false,
            'valid' => 'value',
        ]);

        $this->assertSame('test-command', $args[0]);
        $this->assertSame('', $this->valueAfter($args, '--empty'));  // empty string is still a value
        $this->assertNotContains('--null', $args);                   // null is skipped
        $this->assertSame('0', $this->valueAfter($args, '--zero'));  // 0 is a real value, not "off"
        $this->assertNotContains('--false', $args);                  // false means "flag absent"
        $this->assertSame('value', $this->valueAfter($args, '--valid'));

        // The null positional is skipped; '' and 'valid' survive.
        $this->assertSame(['', 'valid'], array_slice($args, -2));
    }

    /**
     * @param list<mixed> $arguments
     */
    #[DataProvider('argumentTypesProvider')]
    public function testDifferentArgumentTypes(array $arguments, string $description): void
    {
        $args = CommandBuilder::buildCommandArgs('test-cmd', $arguments);

        $this->assertSame('test-cmd', $args[0], "Command building failed for $description");
        foreach (array_slice($args, 1) as $arg) {
            $this->assertIsString($arg, "Every argv entry must be a string for $description");
        }
    }

    /**
     * @return array<int, array{0: list<mixed>, 1: string}>
     */
    public static function argumentTypesProvider(): array
    {
        return [
            [['string-arg'], 'string argument'],
            [[123], 'numeric argument'],
            [[true], 'boolean true argument'],
            [[false], 'boolean false argument'],
            [['arg1', 'arg2', 'arg3'], 'multiple arguments'],
            [[], 'no arguments'],
            [[null], 'null argument'],
            [[''], 'empty string argument'],
        ];
    }

    // -------------------------------------------------------------------------
    // Prefixing rules
    // -------------------------------------------------------------------------

    public function testCurlUsesSingleDashForSingleLetterOptions(): void
    {
        $options = [
            's' => true,
            'verbose' => true,
            'H' => 'Content-Type: application/json',
            'max-time' => 30,
        ];

        $generic = CommandBuilder::buildCommandArgs('generic-tool', ['arg'], $options, CommandBuilder::TYPE_GENERIC);
        $curl = CommandBuilder::buildCommandArgs('curl', ['arg'], $options, CommandBuilder::TYPE_CURL);

        // Generic prefixes everything with --
        $this->assertContains('--s', $generic);
        $this->assertContains('--verbose', $generic);
        $this->assertContains('--H', $generic);
        $this->assertContains('--max-time', $generic);

        // Curl uses - for single letters, -- for long options
        $this->assertContains('-s', $curl);
        $this->assertContains('--verbose', $curl);
        $this->assertContains('-H', $curl);
        $this->assertContains('--max-time', $curl);
    }

    public function testComplexCurlCommand(): void
    {
        $args = CommandBuilder::buildCurlCommandArgs('curl-impersonate', ['https://api.github.com/user'], [
            's' => true,
            'L' => true,
            'w' => '%{http_code}',
            'max-time' => 30,
            'H' => [
                'Accept: application/vnd.github.v3+json',
                'Authorization: Bearer ghp_token123',
                'User-Agent: MyApp/1.0',
            ],
            'data' => '{"query":"user data"}',
            'compressed' => true,
            'location-trusted' => true,
        ]);

        $this->assertSame('curl-impersonate', $args[0]);
        $this->assertSame('https://api.github.com/user', end($args));

        $this->assertContains('-s', $args);
        $this->assertContains('-L', $args);
        $this->assertSame('%{http_code}', $this->valueAfter($args, '-w'));
        $this->assertContains('--compressed', $args);
        $this->assertContains('--location-trusted', $args);

        $this->assertSame(3, count(array_keys($args, '-H', true)));
        $this->assertContains('Accept: application/vnd.github.v3+json', $args);
        $this->assertContains('Authorization: Bearer ghp_token123', $args);
        $this->assertContains('User-Agent: MyApp/1.0', $args);

        // JSON must survive verbatim — no escaping, no mangled quotes.
        $this->assertSame('{"query":"user data"}', $this->valueAfter($args, '--data'));
    }

    // -------------------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------------------

    public function testEmptyExecutableThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Executable cannot be empty');

        CommandBuilder::buildCommandArgs('', ['arg']);
    }

    public function testWhitespaceOnlyExecutableThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Executable cannot be empty');

        CommandBuilder::buildCommandArgs('   ', ['arg']);
    }

    public function testInvalidCommandTypeThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid command type');

        CommandBuilder::buildCommandArgs('test', [], [], 'invalid_type');
    }

    public function testNonStringOptionKeysAreSkipped(): void
    {
        $args = CommandBuilder::buildCommandArgs('test', [], [0 => 'positional-looking', 'real' => 'value']);

        $this->assertNotContains('positional-looking', $args);
        $this->assertSame('value', $this->valueAfter($args, '--real'));
    }

    public function testVeryLongArgumentIsPassedThroughUntouched(): void
    {
        // argv has no shell length limit to guard against, and no escaping step
        // that could fail — a 1MB value must simply arrive intact.
        $long = str_repeat('a', 1000000);

        $args = CommandBuilder::buildCommandArgs('test', [$long]);

        $this->assertSame($long, end($args));
    }

    public function testCommandBuilderConstants(): void
    {
        $this->assertSame('generic', CommandBuilder::TYPE_GENERIC);
        $this->assertSame('curl', CommandBuilder::TYPE_CURL);
    }

    // -------------------------------------------------------------------------
    // Safety
    // -------------------------------------------------------------------------

    /**
     * Shell metacharacters must arrive VERBATIM. proc_open array mode never
     * invokes a shell, so nothing can interpret them — and adding quotes here
     * would corrupt the value, since the program would receive the quotes too.
     */
    #[DataProvider('dangerousInputProvider')]
    public function testShellMetacharactersArePassedThroughVerbatim(string $dangerous): void
    {
        $args = CommandBuilder::buildCommandArgs('safe-command', [$dangerous], ['option' => $dangerous]);

        $this->assertSame('safe-command', $args[0]);
        $this->assertSame($dangerous, $this->valueAfter($args, '--option'));
        $this->assertSame($dangerous, end($args));

        // Never quoted or escaped: exactly two occurrences, both unmodified.
        $this->assertSame(2, count(array_keys($args, $dangerous, true)));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function dangerousInputProvider(): array
    {
        return [
            'command chaining' => ['; rm -rf /'],
            'and chaining' => ['&& echo hacked'],
            'pipe' => ['| cat /etc/passwd'],
            'subshell' => ['$(whoami)'],
            'backticks' => ['`id`'],
            'single quote' => ["O'Reilly"],
            'quote in filename' => ["file'name.txt"],
            'double quotes' => ['file with "quotes"'],
            'spaces' => ['Hello World'],
            'newline' => ["file with\nnewlines"],
            'tab' => ["file with\ttabs"],
            'dollar' => ['file$with$dollars'],
            'braces' => ['file{with}braces'],
        ];
    }
}
