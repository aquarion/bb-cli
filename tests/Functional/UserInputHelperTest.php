<?php

namespace BBCli\BBCli\Tests\Functional;

use BBCli\BBCli\Tests\TestCase;

/**
 * getUserInput() is replaced by a double in tests/bootstrap.php so the rest of
 * the suite can drive interactive prompts. These tests exercise the real
 * readline()-backed implementation in a child process with a real stdin.
 */
class UserInputHelperTest extends TestCase
{
    public function testReturnsTheAnswerTypedOnStdin(): void
    {
        $result = $this->runHelper('getUserInput("Name:", "fallback")', "typed answer\n");

        $this->assertSame(0, $result['exitCode']);
        $this->assertSame('typed answer', $this->returnedValue($result['stdout']));
    }

    public function testReturnsAnEmptyStringForAnEmptyLine(): void
    {
        $result = $this->runHelper('getUserInput("Name:", "fallback")', "\n");

        $this->assertSame('', $this->returnedValue($result['stdout']));
    }

    public function testFallsBackToTheDefaultWhenStdinIsClosed(): void
    {
        $result = $this->runHelper('getUserInput("Name:", "fallback")', '');

        $this->assertSame('fallback', $this->returnedValue($result['stdout']));
    }

    public function testFallsBackToAnEmptyStringWhenNoDefaultIsGiven(): void
    {
        $result = $this->runHelper('getUserInput("Name:")', '');

        $this->assertSame('', $this->returnedValue($result['stdout']));
    }

    public function testConsecutiveCallsConsumeConsecutiveLines(): void
    {
        $result = $this->runHelper(
            'getUserInput("First:")." | ".getUserInput("Second:")',
            "one\ntwo\n"
        );

        $this->assertSame('one | two', $this->returnedValue($result['stdout']));
    }

    /**
     * @return array{stdout: string, stderr: string, exitCode: int}
     */
    private function runHelper(string $call, string $stdin): array
    {
        $helpers = var_export(dirname(__DIR__, 2).'/src/utils/helpers.php', true);

        return $this->runPhp(
            sprintf('require %s; echo "<<<", %s, ">>>";', $helpers, $call),
            null,
            $stdin
        );
    }

    private function returnedValue(string $stdout): string
    {
        $this->assertMatchesRegularExpression('/<<<.*>>>/s', $stdout);
        preg_match('/<<<(.*)>>>/s', $stdout, $matches);

        return $matches[1];
    }
}
