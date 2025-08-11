<?php

namespace Raza\PHPImpersonate\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Raza\PHPImpersonate\Platform\CommandBuilder;
use Raza\PHPImpersonate\Platform\PlatformDetector;
use RuntimeException;

class CommandBuilderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Mock PlatformDetector if needed for consistent testing
        // This assumes PlatformDetector has methods that can be mocked
    }

    /**
     * Test basic generic command building
     */
    public function testBasicGenericCommandBuilding(): void
    {
        $cmd = CommandBuilder::buildCommand('test-command', ['arg1', 'arg2'], [
            'verbose' => true,
            'output' => 'file.txt',
            'config' => 'config.json',
        ]);

        $this->assertIsString($cmd);
        $this->assertStringContainsString('test-command', $cmd);
        $this->assertStringContainsString('--verbose', $cmd);
        $this->assertStringContainsString('--output', $cmd);
        $this->assertStringContainsString('--config', $cmd);

        // Platform-specific quote checking
        if (PlatformDetector::isWindows()) {
            $this->assertStringContainsString('"arg1"', $cmd);
            $this->assertStringContainsString('"arg2"', $cmd);
        } else {
            $this->assertStringContainsString("'arg1'", $cmd);
            $this->assertStringContainsString("'arg2'", $cmd);
        }
    }

    /**
     * Test curl-specific command building
     */
    public function testCurlCommandBuilding(): void
    {
        $cmd = CommandBuilder::buildCurlCommand('curl', ['https://example.com'], [
            's' => true, // silent mode (single letter)
            'L' => true, // follow redirects (single letter)
            'max-time' => 30, // long option
            'H' => ['Content-Type: application/json', 'Authorization: Bearer token'], // multiple headers
            'data' => '{"key":"value"}',
        ]);

        $this->assertIsString($cmd);
        $this->assertStringContainsString('curl', $cmd);
        $this->assertStringContainsString(' -s', $cmd); // single letter option
        $this->assertStringContainsString(' -L', $cmd); // single letter option
        $this->assertStringContainsString('--max-time', $cmd); // long option
        $this->assertStringContainsString(' -H', $cmd); // header option (single letter)
        $this->assertStringContainsString('--data', $cmd); // data option (long option)

        // Platform-specific quote checking
        if (PlatformDetector::isWindows()) {
            $this->assertStringContainsString('"https://example.com"', $cmd);
        } else {
            $this->assertStringContainsString("'https://example.com'", $cmd);
        }
    }

    /**
     * Test curl command with mixed option types
     */
    public function testCurlCommandWithMixedOptions(): void
    {
        $cmd = CommandBuilder::buildCurlCommand('curl-impersonate-chrome', ['https://api.example.com'], [
            's' => true, // single letter boolean
            'verbose' => true, // long boolean
            'w' => '%{http_code}', // single letter with value
            'max-time' => 30, // long option with value
            'user-agent' => 'TestAgent/1.0', // long option with spaces in value
        ]);

        $this->assertStringContainsString(' -s', $cmd);
        $this->assertStringContainsString('--verbose', $cmd);
        $this->assertStringContainsString(' -w', $cmd);

        // Platform-specific quote checking
        if (PlatformDetector::isWindows()) {
            $this->assertStringContainsString('"%{http_code}"', $cmd);
            $this->assertStringContainsString('"30"', $cmd);
            $this->assertStringContainsString('"TestAgent/1.0"', $cmd);
        } else {
            $this->assertStringContainsString("'%{http_code}'", $cmd);
            $this->assertStringContainsString("'30'", $cmd);
            $this->assertStringContainsString("'TestAgent/1.0'", $cmd);
        }

        $this->assertStringContainsString('--max-time', $cmd);
        $this->assertStringContainsString('--user-agent', $cmd);
    }

    /**
     * Test array options (multiple values for same option)
     */
    public function testArrayOptionsHandling(): void
    {
        $headers = ['Content-Type: application/json', 'Authorization: Bearer token123'];

        $cmd = CommandBuilder::buildCommand('test-command', [], [
            'header' => $headers,
            'config' => ['config1.json', 'config2.json'],
        ]);

        // Should contain multiple instances of the same option
        $headerCount = substr_count($cmd, '--header');
        $this->assertEquals(2, $headerCount);

        $configCount = substr_count($cmd, '--config');
        $this->assertEquals(2, $configCount);

        foreach ($headers as $header) {
            $this->assertStringContainsString($header, $cmd);
        }
    }

    /**
     * Test boolean options handling
     */
    #[DataProvider('booleanOptionsProvider')]
    public function testBooleanOptionsHandling(array $options, array $expectedPresent, array $expectedAbsent): void
    {
        $cmd = CommandBuilder::buildCommand('test-command', [], $options);

        foreach ($expectedPresent as $option) {
            $this->assertStringContainsString("--$option", $cmd, "Option --$option should be present");
        }

        foreach ($expectedAbsent as $option) {
            $this->assertStringNotContainsString("--$option", $cmd, "Option --$option should not be present");
        }
    }

    public static function booleanOptionsProvider(): array
    {
        return [
            'mixed boolean options' => [
                'options' => [
                    'verbose' => true,
                    'quiet' => false,
                    'debug' => true,
                    'force' => false,
                ],
                'expectedPresent' => ['verbose', 'debug'],
                'expectedAbsent' => ['quiet', 'force'],
            ],
            'all true options' => [
                'options' => [
                    'help' => true,
                    'version' => true,
                ],
                'expectedPresent' => ['help', 'version'],
                'expectedAbsent' => [],
            ],
            'all false options' => [
                'options' => [
                    'quiet' => false,
                    'silent' => false,
                ],
                'expectedPresent' => [],
                'expectedAbsent' => ['quiet', 'silent'],
            ],
        ];
    }

    /**
     * Test special characters and escaping
     */
    #[DataProvider('specialCharactersProvider')]
    public function testSpecialCharactersEscaping(string $input, string $description): void
    {
        $cmd = CommandBuilder::buildCommand('test', [$input], ['option' => $input]);

        // Command should be built without throwing exceptions
        $this->assertIsString($cmd, "Failed to build command with $description");
        $this->assertStringContainsString('test', $cmd);
    }

    public static function specialCharactersProvider(): array
    {
        return [
            ["Hello World", "spaces"],
            ["file with 'quotes'", "single quotes"],
            ['file with "quotes"', "double quotes"],
            ["file;with;semicolons", "semicolons"],
            ["file|with|pipes", "pipes"],
            ["file&with&ampersands", "ampersands"],
            ["file\$with\$dollars", "dollar signs"],
            ["file`with`backticks", "backticks"],
            ["file(with)parens", "parentheses"],
            ["file[with]brackets", "square brackets"],
            ["file{with}braces", "curly braces"],
            ["file with\ttabs", "tabs"],
            ["file with\nnewlines", "newlines"],
        ];
    }

    /**
     * Test empty and null value handling
     */
    public function testEmptyAndNullValueHandling(): void
    {
        $cmd = CommandBuilder::buildCommand('test-command', [null, '', 'valid'], [
            'empty' => '',
            'null' => null,
            'zero' => 0,
            'false' => false,
            'valid' => 'value',
        ]);

        // Should handle various empty/null values gracefully
        $this->assertIsString($cmd);
        $this->assertStringContainsString('test-command', $cmd);
        $this->assertStringContainsString('--empty', $cmd); // Empty string should still create option
        $this->assertStringNotContainsString('--null', $cmd); // null should be skipped
        $this->assertStringContainsString('--zero', $cmd); // 0 should be included
        $this->assertStringNotContainsString('--false', $cmd); // false should be skipped
        $this->assertStringContainsString('--valid', $cmd);
    }

    /**
     * Test input validation
     */
    public function testEmptyExecutableThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Executable cannot be empty');

        CommandBuilder::buildCommand('', ['arg']);
    }

    public function testWhitespaceOnlyExecutableThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Executable cannot be empty');

        CommandBuilder::buildCommand('   ', ['arg']);
    }

    public function testInvalidCommandTypeThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid command type');

        CommandBuilder::buildCommand('test', [], [], 'invalid_type');
    }

    /**
     * Test path escaping functionality
     */
    #[DataProvider('pathEscapingProvider')]
    public function testPathEscaping(string $input, string $description): void
    {
        $escapedPath = CommandBuilder::escapePath($input);

        $this->assertIsString($escapedPath, "Path escaping failed for $description");
        $this->assertNotEmpty($escapedPath, "Escaped path should not be empty for $description");
    }

    public static function pathEscapingProvider(): array
    {
        return [
            ['/simple/unix/path', 'simple Unix path'],
            ['/path/with spaces/file.txt', 'Unix path with spaces'],
            ['/path/with/special&chars$/file.txt', 'Unix path with special characters'],
            ['C:\\Windows\\System32', 'Windows path'],
            ['C:\\Program Files\\Application', 'Windows path with spaces'],
            ['\\\\server\\share\\file.txt', 'UNC path'],
            ['relative/path/file.txt', 'relative path'],
        ];
    }

    /**
     * Test empty path handling
     */
    public function testEmptyPathEscaping(): void
    {
        $escapedPath = CommandBuilder::escapePath('');

        $this->assertIsString($escapedPath, "Empty path escaping failed");
        $this->assertEquals('', $escapedPath, "Empty path should return empty string");
    }

    /**
     * Test command building with different argument types
     */
    #[DataProvider('argumentTypesProvider')]
    public function testDifferentArgumentTypes(array $args, string $description): void
    {
        $cmd = CommandBuilder::buildCommand('test-cmd', $args);

        $this->assertIsString($cmd, "Command building failed for $description");
        $this->assertStringContainsString('test-cmd', $cmd);
    }

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

    /**
     * Test curl vs generic command differences
     */
    public function testCurlVsGenericCommandDifferences(): void
    {
        $options = [
            's' => true, // single letter
            'verbose' => true, // long option
            'H' => 'Content-Type: application/json', // single letter with value
            'max-time' => 30, // long option with value
        ];

        $genericCmd = CommandBuilder::buildCommand('generic-tool', ['arg'], $options, CommandBuilder::TYPE_GENERIC);
        $curlCmd = CommandBuilder::buildCommand('curl', ['arg'], $options, CommandBuilder::TYPE_CURL);

        // Generic should use -- for all options
        $this->assertStringContainsString('--s', $genericCmd);
        $this->assertStringContainsString('--verbose', $genericCmd);
        $this->assertStringContainsString('--H', $genericCmd);
        $this->assertStringContainsString('--max-time', $genericCmd);

        // Curl should use - for single letters, -- for long options
        $this->assertStringContainsString(' -s', $curlCmd);
        $this->assertStringContainsString('--verbose', $curlCmd);
        $this->assertStringContainsString(' -H', $curlCmd);
        $this->assertStringContainsString('--max-time', $curlCmd);
    }

    /**
     * Test complex real-world curl command
     */
    public function testComplexCurlCommand(): void
    {
        $cmd = CommandBuilder::buildCurlCommand('curl-impersonate-chrome', ['https://api.github.com/user'], [
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

        // Verify structure
        $this->assertStringStartsWith('curl-impersonate-chrome', trim($cmd));

        // Platform-specific URL ending check
        if (PlatformDetector::isWindows()) {
            $this->assertStringEndsWith('"https://api.github.com/user"', trim($cmd));
        } else {
            $this->assertStringEndsWith("'https://api.github.com/user'", trim($cmd));
        }

        // Verify options are present
        $this->assertStringContainsString(' -s', $cmd);
        $this->assertStringContainsString(' -L', $cmd);
        $this->assertStringContainsString(' -w', $cmd);
        $this->assertStringContainsString('--max-time', $cmd);
        $this->assertStringContainsString('--compressed', $cmd);
        $this->assertStringContainsString('--location-trusted', $cmd);

        // Verify headers
        $this->assertEquals(3, substr_count($cmd, ' -H '));
        $this->assertStringContainsString('Accept: application/vnd.github.v3+json', $cmd);
        $this->assertStringContainsString('Authorization: Bearer ghp_token123', $cmd);
        $this->assertStringContainsString('User-Agent: MyApp/1.0', $cmd);

        // Verify data
        $this->assertStringContainsString('--data', $cmd);

        // Platform-specific JSON data checking
        if (PlatformDetector::isWindows()) {
            $this->assertStringContainsString('"{\\"query\\":\\"user data\\"}"', $cmd);
        } else {
            $this->assertStringContainsString('{"query":"user data"}', $cmd);
        }
    }

    /**
     * Test error handling for failed escaping
     */
    public function testErrorHandlingForFailedOperations(): void
    {
        // Test with an extremely long string that might cause issues
        $veryLongString = str_repeat('a', 1000000); // 1MB string

        try {
            $cmd = CommandBuilder::buildCommand('test', [$veryLongString]);
            $this->assertIsString($cmd);
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Failed to build command', $e->getMessage());
        }
    }

    /**
     * Test command builder constants
     */
    public function testCommandBuilderConstants(): void
    {
        $this->assertEquals('generic', CommandBuilder::TYPE_GENERIC);
        $this->assertEquals('curl', CommandBuilder::TYPE_CURL);
    }

    /**
     * Test that commands are properly escaped and safe
     */
    public function testCommandSafety(): void
    {
        // Test potentially dangerous inputs
        $dangerousInputs = [
            '; rm -rf /',
            '&& echo hacked',
            '| cat /etc/passwd',
            '$(whoami)',
            '`id`',
            "O'Reilly", // Test single quote escaping
            "file'name.txt", // Test single quote in filename
        ];

        foreach ($dangerousInputs as $dangerous) {
            $cmd = CommandBuilder::buildCommand('safe-command', [$dangerous], ['option' => $dangerous]);

            // The dangerous parts should be properly escaped
            $this->assertIsString($cmd);
            $this->assertStringContainsString('safe-command', $cmd);

            // Get the properly escaped version using escapeshellarg()
            $escapedDangerous = escapeshellarg($dangerous);

            // Verify that the command contains the properly escaped dangerous content
            $this->assertStringContainsString(
                $escapedDangerous,
                $cmd,
                "Dangerous content should be properly escaped: {$dangerous} -> {$escapedDangerous}"
            );

            // Verify that the command structure is safe
            // The command should start with the executable and contain properly quoted arguments
            $this->assertStringStartsWith('safe-command', trim($cmd));

            // Verify that the command contains the expected number of escaped arguments
            // Each dangerous input should appear twice: once as an argument, once as an option value
            $expectedOccurrences = 2;
            $actualOccurrences = substr_count($cmd, $escapedDangerous);
            $this->assertEquals(
                $expectedOccurrences,
                $actualOccurrences,
                "Command should contain dangerous content exactly {$expectedOccurrences} times (as argument and option): {$cmd}"
            );
        }
    }
}
