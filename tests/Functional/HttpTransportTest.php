<?php

namespace BBCli\BBCli\Tests\Functional;

use BBCli\BBCli\Base;
use BBCli\BBCli\Tests\TestCase;

/**
 * Exercises Base::executeRequest() against a real HTTP server.
 *
 * The rest of the suite replaces this method, so this is the one place the
 * actual curl setup — method, headers, body, status and error reporting — is
 * checked end to end.
 */
class HttpTransportTest extends TestCase
{
    /** @var resource|null */
    private static $server;

    /** @var string */
    private static $baseUrl;

    /** @var string|null */
    private static $docroot;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        $docroot = sys_get_temp_dir().'/bb-http-'.bin2hex(random_bytes(6));
        mkdir($docroot, 0777, true);
        file_put_contents($docroot.'/router.php', self::routerSource());
        self::$docroot = $docroot;

        $port = self::findFreePort();
        self::$baseUrl = "http://127.0.0.1:{$port}";

        $descriptors = [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']];
        self::$server = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $docroot, $docroot.'/router.php'],
            $descriptors,
            $pipes,
            $docroot
        );

        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        self::waitForServer(self::$baseUrl.'/ping');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
            self::$server = null;
        }

        if (!is_null(self::$docroot)) {
            @unlink(self::$docroot.'/router.php');
            @rmdir(self::$docroot);
            self::$docroot = null;
        }

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->writeApiTokenConfig('dev@example.com', 'tok-123');
    }

    /**
     * Calls the protected transport directly.
     *
     * @param  array<string, mixed> $payload
     * @return array{body: string|bool, status: int, error: string|null}
     */
    private function request(string $method, string $path, array $payload = []): array
    {
        $base = new Base();

        $reflection = new \ReflectionMethod($base, 'executeRequest');
        $reflection->setAccessible(true);

        return $reflection->invoke($base, $method, self::$baseUrl.$path, $payload);
    }

    public function testPerformsAGetRequestAndReturnsTheBodyAndStatus(): void
    {
        $response = $this->request('GET', '/echo');

        $this->assertNull($response['error']);
        $this->assertSame(200, $response['status']);

        $echo = json_decode($response['body'], true);

        $this->assertSame('GET', $echo['method']);
        $this->assertSame('/echo', $echo['path']);
    }

    public function testSendsTheJsonContentTypeAndAuthorizationHeaders(): void
    {
        $echo = json_decode($this->request('GET', '/echo')['body'], true);

        $this->assertSame('application/json', $echo['headers']['content-type']);
        $this->assertSame(
            'Basic '.base64_encode('dev@example.com:tok-123'),
            $echo['headers']['authorization']
        );
    }

    public function testSendsABearerTokenWhenOauthIsConfigured(): void
    {
        $this->writeUserConfig(['auth' => ['oauthToken' => 'oauth-abc']]);

        $echo = json_decode($this->request('GET', '/echo')['body'], true);

        $this->assertSame('Bearer oauth-abc', $echo['headers']['authorization']);
    }

    public function testPostsAJsonEncodedPayload(): void
    {
        $echo = json_decode($this->request('POST', '/echo', ['title' => 'Hello', 'draft' => true])['body'], true);

        $this->assertSame('POST', $echo['method']);
        $this->assertSame(['title' => 'Hello', 'draft' => true], json_decode($echo['body'], true));
    }

    public function testUppercasesTheHttpMethod(): void
    {
        $echo = json_decode($this->request('delete', '/echo')['body'], true);

        $this->assertSame('DELETE', $echo['method']);
    }

    public function testDoesNotSendABodyOnGetRequests(): void
    {
        $echo = json_decode($this->request('GET', '/echo', ['ignored' => true])['body'], true);

        $this->assertSame('', $echo['body']);
    }

    public function testSendsAPutBody(): void
    {
        $echo = json_decode($this->request('PUT', '/echo', ['draft' => false])['body'], true);

        $this->assertSame('PUT', $echo['method']);
        $this->assertSame(['draft' => false], json_decode($echo['body'], true));
    }

    public function testReportsNonSuccessStatusCodes(): void
    {
        $response = $this->request('GET', '/status/403');

        $this->assertSame(403, $response['status']);
        $this->assertNull($response['error']);
    }

    public function testFollowsRedirects(): void
    {
        $response = $this->request('GET', '/redirect');

        $this->assertSame(200, $response['status']);
        $this->assertSame('GET', json_decode($response['body'], true)['method']);
    }

    public function testReportsTransportErrors(): void
    {
        // Port 1 on loopback refuses connections.
        $base = new Base();
        $reflection = new \ReflectionMethod($base, 'executeRequest');
        $reflection->setAccessible(true);

        $response = $reflection->invoke($base, 'GET', 'http://127.0.0.1:1/nope', []);

        $this->assertNotNull($response['error']);
        $this->assertFalse($response['body']);
        $this->assertSame(0, $response['status']);
    }

    public function testMakeRequestDecodesARealJsonResponse(): void
    {
        $base = new class extends Base {
            public $lastUrl;

            protected function executeRequest($method, $url, $payload = [])
            {
                // Route the api base url at the local test server.
                $this->lastUrl = str_replace(Base::API_BASE_URL, HttpTransportTest::baseUrl(), $url);

                return parent::executeRequest($method, $this->lastUrl, $payload);
            }
        };

        $GLOBALS['bb_cli_project_url'] = 'acme/widgets';

        $result = $base->makeRequest('GET', '/echo');

        $this->assertSame('/repositories/acme/widgets/echo', $result['path']);
        $this->assertStringEndsWith('/repositories/acme/widgets/echo', $base->lastUrl);
    }

    public static function baseUrl(): string
    {
        return self::$baseUrl;
    }

    private static function findFreePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        if ($socket === false) {
            throw new \RuntimeException("Could not reserve a port: {$errstr}");
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    private static function waitForServer(string $url): void
    {
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $handle = @fsockopen(
                parse_url($url, PHP_URL_HOST),
                (int) parse_url($url, PHP_URL_PORT),
                $errno,
                $errstr,
                0.1
            );

            if ($handle) {
                fclose($handle);

                return;
            }

            usleep(50000);
        }

        throw new \RuntimeException('The test HTTP server did not start.');
    }

    private static function routerSource(): string
    {
        return <<<'PHP'
        <?php

        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

        if ($path === '/ping') {
            echo 'pong';

            return true;
        }

        if ($path === '/redirect') {
            header('Location: /echo', true, 302);

            return true;
        }

        if (preg_match('#^/status/(\d+)$#', $path, $matches)) {
            http_response_code((int) $matches[1]);
            echo json_encode(['type' => 'error', 'error' => ['message' => 'nope']]);

            return true;
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }

        header('Content-Type: application/json');
        echo json_encode([
            'method' => $_SERVER['REQUEST_METHOD'],
            'path' => $path,
            'headers' => $headers,
            'body' => file_get_contents('php://input'),
        ]);

        return true;
        PHP;
    }
}
