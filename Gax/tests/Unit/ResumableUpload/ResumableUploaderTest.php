<?php
/*
 * Copyright 2026 Google LLC
 * All rights reserved.
 */

namespace Google\ApiCore\Tests\Unit\ResumableUpload;

use Google\ApiCore\ResumableUpload\ResumableUploader;
use Google\ApiCore\Transport\TransportInterface;
use Google\Protobuf\Internal\Message;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;

class TestTransport implements TransportInterface
{
    public array $requests = [];
    public array $responses = [];
    private int $respIdx = 0;

    public function __construct(array $responses = [])
    {
        $this->responses = $responses;
    }

    public function sendRequest(\Psr\Http\Message\RequestInterface $request, array $options = [])
    {
        $this->requests[] = $request;
        $resp = $this->responses[$this->respIdx++] ?? new Response(200, ['X-Goog-Upload-Status' => 'final']);
        $promise = new Promise(function () use (&$promise, $resp) {
            $promise->resolve($resp);
        });
        return $promise;
    }

    public function startUnaryCall(\Google\ApiCore\Call $call, array $options) {}
    public function startBidiStreamingCall(\Google\ApiCore\Call $call, array $options) {}
    public function startClientStreamingCall(\Google\ApiCore\Call $call, array $options) {}
    public function startServerStreamingCall(\Google\ApiCore\Call $call, array $options) {}
    public function close() {}
}

class ResumableUploaderTest extends TestCase
{
    public function testNewUploadFlow()
    {
        $transport = new TestTransport([
            new Response(200, ['X-Goog-Upload-Status' => 'active', 'X-Goog-Upload-URL' => 'https://upload.url/123']),
            new Response(200, ['X-Goog-Upload-Status' => 'final'])
        ]);

        $uploader = new ResumableUploader(
            $transport,
            serviceAddress: 'test.googleapis.com',
            uploadPrefix: '/resumable/upload',
            restPath: 'v1/test:create',
            requestMessage: new \Google\Protobuf\Timestamp()
        );

        $stream = Utils::streamFor('hello world');
        $result = $uploader->startUpload($stream);

        $this->assertTrue($result);
        $this->assertCount(2, $transport->requests);
        $this->assertEquals('POST', $transport->requests[0]->getMethod());
        $this->assertEquals('https://test.googleapis.com/resumable/upload/v1/test:create', (string) $transport->requests[0]->getUri());
        $this->assertEquals('start', $transport->requests[0]->getHeaderLine('X-Goog-Upload-Command'));
        $this->assertEquals('POST', $transport->requests[1]->getMethod());
        $this->assertEquals('upload, finalize', $transport->requests[1]->getHeaderLine('X-Goog-Upload-Command'));
        $this->assertEquals('hello world', (string) $transport->requests[1]->getBody());
    }


    public function testResumeUploadFlow()
    {
        $transport = new TestTransport([
            new Response(200, ['X-Goog-Upload-Status' => 'active', 'X-Goog-Upload-Size-Received' => '5']),
            new Response(200, ['X-Goog-Upload-Status' => 'final'])
        ]);

        $uploader = new ResumableUploader(
            $transport,
            uploadUrl: 'https://upload.url/123'
        );

        $stream = Utils::streamFor('hello world');
        $result = $uploader->startUpload($stream);

        $this->assertTrue($result);
        $this->assertCount(2, $transport->requests);
        $this->assertEquals('query', $transport->requests[0]->getHeaderLine('X-Goog-Upload-Command'));
        $this->assertEquals('https://upload.url/123', (string) $transport->requests[0]->getUri());
        $this->assertEquals('upload, finalize', $transport->requests[1]->getHeaderLine('X-Goog-Upload-Command'));
        $this->assertEquals(' world', (string) $transport->requests[1]->getBody());
    }
}
