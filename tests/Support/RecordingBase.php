<?php

namespace BBCli\BBCli\Tests\Support;

use BBCli\BBCli\Base;

/**
 * A Base subclass whose transport is a canned response queue.
 *
 * Everything makeRequest() does around the HTTP call — url building, status
 * handling, JSON decoding — still runs; only executeRequest() is replaced.
 */
class RecordingBase extends Base
{
    /** @var array<array{method: string, url: string, payload: array}> */
    public $requests = [];

    /** @var array<array{body: string|bool, status: int, error: string|null}> */
    private $responses = [];

    /**
     * Queues a raw response body and status for the next request.
     *
     * @param string|bool $body
     */
    public function queueResponse($body, int $status = 200, ?string $error = null): self
    {
        $this->responses[] = ['body' => $body, 'status' => $status, 'error' => $error];

        return $this;
    }

    /**
     * Queues a JSON-encoded response for the next request.
     *
     * @param mixed $data
     */
    public function queueJson($data, int $status = 200): self
    {
        return $this->queueResponse(json_encode($data), $status);
    }

    /**
     * The Authorization header the current config would produce.
     */
    public function authHeader(): string
    {
        return $this->buildAuthHeader();
    }

    protected function executeRequest($method, $url, $payload = [])
    {
        $this->requests[] = [
            'method' => $method,
            'url' => $url,
            'payload' => $payload,
            'authHeader' => $this->buildAuthHeader(),
        ];

        if (empty($this->responses)) {
            throw new \RuntimeException("No queued response for {$method} {$url}");
        }

        return array_shift($this->responses);
    }
}
