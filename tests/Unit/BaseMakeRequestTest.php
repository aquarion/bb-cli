<?php

namespace BBCli\BBCli\Tests\Unit;

use BBCli\BBCli\Base;
use BBCli\BBCli\Tests\Support\RecordingBase;
use BBCli\BBCli\Tests\TestCase;

class BaseMakeRequestTest extends TestCase
{
    /** @var RecordingBase */
    private $base;

    protected function setUp(): void
    {
        parent::setUp();

        $this->writeApiTokenConfig();
        $GLOBALS['bb_cli_project_url'] = 'acme/widgets';

        $this->base = new RecordingBase();
    }

    public function testPrefixesRepositoryUrls(): void
    {
        $this->base->queueJson(['ok' => true]);

        $this->base->makeRequest('GET', '/pullrequests?state=OPEN');

        $this->assertSame(
            Base::API_BASE_URL.'/repositories/acme/widgets/pullrequests?state=OPEN',
            $this->base->requests[0]['url']
        );
    }

    public function testLeavesNonRepositoryUrlsAlone(): void
    {
        $this->base->queueJson(['uuid' => '{1}']);

        $this->base->makeRequest('GET', '/user', [], false);

        $this->assertSame(Base::API_BASE_URL.'/user', $this->base->requests[0]['url']);
    }

    public function testPassesMethodAndPayloadToTheTransport(): void
    {
        $this->base->queueJson(['id' => 7]);

        $this->base->makeRequest('POST', '/pullrequests', ['title' => 'Hello'], true);

        $this->assertSame('POST', $this->base->requests[0]['method']);
        $this->assertSame(['title' => 'Hello'], $this->base->requests[0]['payload']);
    }

    public function testUsesBasicAuthForApiTokenCredentials(): void
    {
        $this->writeApiTokenConfig('dev@example.com', 'tok123');

        $this->assertSame(
            'Authorization: Basic '.base64_encode('dev@example.com:tok123'),
            $this->base->authHeader()
        );
    }

    public function testUsesBearerAuthWhenAnOauthTokenIsConfigured(): void
    {
        $this->writeUserConfig([
            'auth' => [
                'oauthToken' => 'oauth-abc',
                'email' => 'dev@example.com',
                'apiToken' => 'tok123',
            ],
        ]);

        $this->assertSame('Authorization: Bearer oauth-abc', $this->base->authHeader());
    }

    public function testSendsTheAuthorizationHeaderWithEveryRequest(): void
    {
        $this->base->queueJson([]);

        $this->base->makeRequest('GET', '/x');

        $this->assertSame(
            'Authorization: Basic '.base64_encode('dev@example.com:secret-token'),
            $this->base->requests[0]['authHeader']
        );
    }

    public function testDecodesJsonResponses(): void
    {
        $this->base->queueJson(['values' => [['id' => 1]]]);

        $this->assertSame(['values' => [['id' => 1]]], $this->base->makeRequest('GET', '/x'));
    }

    public function testReturnsNonJsonBodiesUnchanged(): void
    {
        $diff = "diff --git a/file b/file\n+added\n";
        $this->base->queueResponse($diff);

        $this->assertSame($diff, $this->base->makeRequest('GET', '/pullrequests/1/diff'));
    }

    public function testThrowsOnUnauthorizedResponses(): void
    {
        $this->base->queueJson(['type' => 'error'], 401);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Authorization error, please check your credentials.');

        $this->base->makeRequest('GET', '/x');
    }

    public function testForbiddenResponseMentionsTheOperationAndApiTokenScopes(): void
    {
        $this->base->queueJson(['type' => 'error'], 403);

        try {
            $this->base->makeRequest('POST', '/x', [], true, 'approving pull request');
            $this->fail('Expected an exception for a 403 response.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('Permission denied while approving pull request.', $e->getMessage());
            $this->assertStringContainsString('Your API token may not have the required scope.', $e->getMessage());
            $this->assertStringContainsString('https://bitbucket.org/account/settings/api-tokens/', $e->getMessage());
        }
    }

    public function testForbiddenResponseMentionsOauthScopesForOauthCredentials(): void
    {
        $this->writeUserConfig(['auth' => ['oauthToken' => 'oauth-abc']]);
        $this->base->queueJson(['type' => 'error'], 403);

        try {
            $this->base->makeRequest('GET', '/x', [], true, 'listing branches');
            $this->fail('Expected an exception for a 403 response.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('Your OAuth token may not have the required scope.', $e->getMessage());
            $this->assertStringNotContainsString('api-tokens', $e->getMessage());
        }
    }

    public function testForbiddenResponseWithoutAnOperationLabelOmitsTheContext(): void
    {
        $this->base->queueJson(['type' => 'error'], 403);

        try {
            $this->base->makeRequest('GET', '/x');
            $this->fail('Expected an exception for a 403 response.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('Permission denied. ', $e->getMessage());
            $this->assertStringNotContainsString('while', $e->getMessage());
        }
    }

    public function testConflictResponsesAreReturnedRatherThanThrown(): void
    {
        $this->base->queueJson(['error' => ['message' => 'merge conflict']], 409);

        $this->assertSame(
            ['error' => ['message' => 'merge conflict']],
            $this->base->makeRequest('POST', '/pullrequests/1/merge')
        );
    }

    public function testOtherErrorStatusesPrintTheBodyAndThrow(): void
    {
        $this->base->queueResponse('{"detail":"boom"}', 500);

        $output = '';

        try {
            $output = $this->captureOutput(function () {
                $this->base->makeRequest('GET', '/x');
            });
            $this->fail('Expected an exception for a 500 response.');
        } catch (\Exception $e) {
            $this->assertSame('An error occurred, status code: 500', $e->getMessage());
        }

        $this->assertSame('', $output, 'captureOutput returns before the exception propagates.');
    }

    public function testErrorTypedJsonBodiesAreThrownWithTheApiMessage(): void
    {
        $this->base->queueJson([
            'type' => 'error',
            'error' => ['message' => 'Branch "nope" not found'],
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Branch "nope" not found');

        $this->base->makeRequest('GET', '/x');
    }

    public function testSuccessfulResponsesWithoutAnErrorTypeAreNotThrown(): void
    {
        $this->base->queueJson(['type' => 'pullrequest', 'id' => 3]);

        $this->assertSame(['type' => 'pullrequest', 'id' => 3], $this->base->makeRequest('GET', '/x'));
    }
}
