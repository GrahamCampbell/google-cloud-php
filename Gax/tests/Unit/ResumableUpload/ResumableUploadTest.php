<?php
/*
 * Copyright 2026 Google LLC
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are
 * met:
 *
 *     * Redistributions of source code must retain the above copyright
 * notice, this list of conditions and the following disclaimer.
 *     * Redistributions in binary form must reproduce the above
 * copyright notice, this list of conditions and the following disclaimer
 * in the documentation and/or other materials provided with the
 * distribution.
 *     * Neither the name of Google Inc. nor the names of its
 * contributors may be used to endorse or promote products derived from
 * this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */

namespace Google\ApiCore\Tests\Unit\ResumableUpload;

use Google\ApiCore\ResumableUpload\ResumableUpload;
use Google\ApiCore\ResumableUpload\ResumableUploadClient;
use Google\Protobuf\Timestamp;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;

class ResumableUploadTest extends TestCase
{
    public function testInitializationAndReflection()
    {
        $httpHandler = function () {
        };
        $requestBuilder = $this->createMock(\Google\ApiCore\RequestBuilder::class);
        $client = new ResumableUploadClient($requestBuilder, $httpHandler, serviceAddress: 'test.googleapis.com');

        $upload = new ResumableUpload($client, 'v1/test:create', new Timestamp(), null, [
            'chunkSize' => 1024,
            'progressCallback' => function (int $bytes) {
            }
        ]);

        $ref = new \ReflectionClass($upload);
        $clientProp = $ref->getProperty('resumableUploadClient');
        $this->assertSame($client, $clientProp->getValue($upload));

        $pathProp = $ref->getProperty('method');
        $this->assertSame('v1/test:create', $pathProp->getValue($upload));
    }

    public function testProgressCallbackReceivesUploadUrl()
    {
        $httpHandler = $this->createMockHttpHandler([
            new Response(200, ['X-Goog-Upload-Status' => 'active', 'X-Goog-Upload-URL' => 'https://upload.url/123']),
            new Response(200, ['X-Goog-Upload-Status' => 'final'])
        ]);

        $requestBuilder = $this->createMock(\Google\ApiCore\RequestBuilder::class);
        $requestBuilder->method('build')->willReturnCallback(function ($path, $message, $headers = []) {
            return new \GuzzleHttp\Psr7\Request('POST', 'https://test.googleapis.com/' . $path, $headers);
        });
        $client = new ResumableUploadClient($requestBuilder, $httpHandler, serviceAddress: 'test.googleapis.com');
        $callbackUrl = null;
        $upload = new ResumableUpload($client, 'v1/test:create', new Timestamp(), null, [
            'progressCallback' => function (int $bytes, string $url) use (&$callbackUrl) {
                $callbackUrl = $url;
            }
        ]);

        $stream = Utils::streamFor('hello world');
        $upload->startUpload($stream);

        $this->assertSame('https://upload.url/123', $callbackUrl);
    }

    public function testStartUploadDelegation()
    {
        $requests = [];
        $httpHandler = $this->createMockHttpHandler([
            new Response(200, ['X-Goog-Upload-Status' => 'active', 'X-Goog-Upload-URL' => 'https://upload.url/123']),
            new Response(200, ['X-Goog-Upload-Status' => 'final'])
        ], $requests);

        $requestBuilder = $this->createMock(\Google\ApiCore\RequestBuilder::class);
        $requestBuilder->method('build')->willReturnCallback(function ($path, $message, $headers = []) {
            return new \GuzzleHttp\Psr7\Request('POST', 'https://test.googleapis.com/' . $path, $headers);
        });
        $client = new ResumableUploadClient($requestBuilder, $httpHandler, serviceAddress: 'test.googleapis.com');
        $upload = new ResumableUpload($client, 'v1/test:create', new Timestamp());

        $stream = Utils::streamFor('hello world');
        $result = $upload->startUpload($stream);

        $this->assertTrue($result);
        $this->assertCount(2, $requests);
        $this->assertEquals('POST', $requests[0]->getMethod());
        $this->assertEquals('start', $requests[0]->getHeaderLine('X-Goog-Upload-Command'));
        $this->assertEquals('upload, finalize', $requests[1]->getHeaderLine('X-Goog-Upload-Command'));
        $this->assertEquals('hello world', (string) $requests[1]->getBody());
        $this->assertEquals('https://upload.url/123', $upload->getUploadUrl());
    }

    public function testUploadUrlTrackingAndResume()
    {
        $requests = [];
        $httpHandler = $this->createMockHttpHandler([
            new Response(200, ['X-Goog-Upload-Status' => 'active', 'X-Goog-Upload-Size-Received' => '5']),
            new Response(200, ['X-Goog-Upload-Status' => 'final'])
        ], $requests);

        $requestBuilder = $this->createMock(\Google\ApiCore\RequestBuilder::class);
        $client = new ResumableUploadClient($requestBuilder, $httpHandler, serviceAddress: 'test.googleapis.com');
        $upload = new ResumableUpload($client, '', null, 'https://upload.url/session123');
        $this->assertEquals('https://upload.url/session123', $upload->getUploadUrl());

        $stream = Utils::streamFor('hello world');
        $result = $upload->startUpload($stream);

        $this->assertTrue($result);
        $this->assertCount(2, $requests);
        $this->assertEquals('query', $requests[0]->getHeaderLine('X-Goog-Upload-Command'));
        $this->assertEquals('upload, finalize', $requests[1]->getHeaderLine('X-Goog-Upload-Command'));
        $this->assertEquals(' world', (string) $requests[1]->getBody());
    }

    public function testInvalidInitializationThrowsException()
    {
        $this->expectException(\Google\ApiCore\ValidationException::class);
        $this->expectExceptionMessage('Cannot initialize ResumableUpload without either a valid ($method, $requestMessage) pair to start an upload, or an $uploadUrl to resume an upload.');

        $requestBuilder = $this->createMock(\Google\ApiCore\RequestBuilder::class);
        $client = new ResumableUploadClient($requestBuilder, function () {});
        new ResumableUpload($client, '', null);
    }

    private function createMockHttpHandler(array $responses, ?array &$requests = []): callable
    {
        return function ($request, $options = []) use (&$responses, &$requests) {
            $requests[] = $request;
            $response = array_shift($responses);
            if ($response instanceof \Exception) {
                return Create::rejectionFor($response);
            }
            return Create::promiseFor($response);
        };
    }
}
