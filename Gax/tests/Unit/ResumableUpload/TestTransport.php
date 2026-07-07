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

use Google\ApiCore\Call;
use Google\ApiCore\Transport\TransportInterface;
use GuzzleHttp\Promise\Create;
use Psr\Http\Message\RequestInterface;

class TestTransport implements TransportInterface
{
    public array $requests = [];
    public array $responses = [];

    public function __construct(array $responses = [])
    {
        $this->responses = $responses;
    }

    public function sendRequest(RequestInterface $request, array $options = []): \GuzzleHttp\Promise\PromiseInterface
    {
        $this->requests[] = $request;
        $response = array_shift($this->responses);
        if ($response instanceof \Exception) {
            return Create::rejectionFor($response);
        }
        return Create::promiseFor($response);
    }

    public function close(): void
    {
    }

    public function getBaseUri(): string
    {
        return 'https://test.googleapis.com';
    }

    public function startBidiStreamingCall(Call $call, array $options) {}
    public function startClientStreamingCall(Call $call, array $options) {}
    public function startServerStreamingCall(Call $call, array $options) {}
    public function startUnaryCall(Call $call, array $options) {}
}
