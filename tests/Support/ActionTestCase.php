<?php

namespace BBCli\BBCli\Tests\Support;

use BBCli\BBCli\Tests\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Base class for action tests.
 *
 * Actions reach the network through the public Base::makeRequest(), so tests
 * replace just that method and assert on the request arguments and the output
 * the action prints.
 */
abstract class ActionTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->writeApiTokenConfig();

        // Actions that build repository urls call getRepoPath(); pin it so tests
        // never depend on the checkout they happen to run in.
        $GLOBALS['bb_cli_project_url'] = 'acme/widgets';
    }

    /**
     * Builds an action with makeRequest() replaced by a mock.
     *
     * @template T of \BBCli\BBCli\Base
     * @param  class-string<T> $class
     * @return T&MockObject
     */
    protected function action(string $class)
    {
        return $this->getMockBuilder($class)
            ->onlyMethods(['makeRequest'])
            ->getMock();
    }

    /**
     * Builds an action whose makeRequest() answers from a url-keyed map.
     *
     * Keys are matched as substrings of the request url, so callers can key on
     * the distinctive part of an endpoint. Every call is recorded in $recorded.
     *
     * @param  array<string, mixed> $responses
     * @param  array<int, array{method: string, url: string, payload: array, isRepositoryUrl: bool, label: string|null}> $recorded
     * @return \BBCli\BBCli\Base&MockObject
     */
    protected function actionRouting(string $class, array $responses, array &$recorded = [])
    {
        $action = $this->action($class);
        $recorded = [];

        $action->method('makeRequest')->willReturnCallback(
            function ($method = 'GET', $url = '', $payload = [], $isRepositoryUrl = true, $label = null) use ($responses, &$recorded) {
                $recorded[] = [
                    'method' => $method,
                    'url' => $url,
                    'payload' => $payload,
                    'isRepositoryUrl' => $isRepositoryUrl,
                    'label' => $label,
                ];

                foreach ($responses as $needle => $response) {
                    if (strpos($url, (string) $needle) !== false) {
                        if ($response instanceof \Throwable) {
                            throw $response;
                        }

                        return $response;
                    }
                }

                throw new \RuntimeException("Unexpected request: {$method} {$url}");
            }
        );

        return $action;
    }

    /**
     * Splits captured output into trimmed, non-empty lines.
     *
     * @return array<string>
     */
    protected function lines(string $output): array
    {
        return array_values(array_filter(array_map('trim', explode(PHP_EOL, $output)), function ($line) {
            return $line !== '';
        }));
    }

    /**
     * Calls a private or protected method.
     *
     * @param  array<mixed> $args
     * @return mixed
     */
    protected function callPrivate(object $target, string $method, array $args = [])
    {
        $reflection = new \ReflectionMethod($target, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($target, $args);
    }
}
