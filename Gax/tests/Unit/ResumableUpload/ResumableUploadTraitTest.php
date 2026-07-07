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

use Google\ApiCore\CredentialsWrapper;
use Google\ApiCore\ResumableUpload\ResumableUploadTrait;
use Google\ApiCore\ResumableUpload\ResumableUpload;
use Google\ApiCore\ResumableUpload\ResumableUploadClient;
use PHPUnit\Framework\TestCase;

class TestTraitClient
{
    use ResumableUploadTrait;

    public $transport;
    public $credentialsWrapper;
    public array $agentHeader = [];
    public string $apiEndpoint = 'test.googleapis.com';

    public function __construct($transport, ?CredentialsWrapper $credentialsWrapper = null)
    {
        $this->transport = $transport;
        $this->credentialsWrapper = $credentialsWrapper;
        $this->resumableUploadClient = $this->createResumableUploadClient([]);
    }

    public function exposeGetResumableUploadClient(): ResumableUploadClient
    {
        return $this->getResumableUploadClient();
    }
}

class ResumableUploadTraitTest extends TestCase
{
    public function testTraitClientCreationAndResumeUpload()
    {
        $transport = new TestTransport();
        $credentialsWrapper = $this->createMock(CredentialsWrapper::class);

        $client = new TestTraitClient($transport, $credentialsWrapper);

        $uploadClient = $client->exposeGetResumableUploadClient();
        $this->assertInstanceOf(ResumableUploadClient::class, $uploadClient);
        $clientRef = new \ReflectionClass($uploadClient);
        $this->assertSame($transport, $clientRef->getProperty('transport')->getValue($uploadClient));
        $this->assertSame($credentialsWrapper, $clientRef->getProperty('credentialsWrapper')->getValue($uploadClient));

        $resumed = $client->resumeUpload('https://upload.url/session123', 1024);
        $this->assertInstanceOf(ResumableUpload::class, $resumed);

        $ref = new \ReflectionClass($resumed);
        $clientProp = $ref->getProperty('resumableUploadClient');
        $this->assertSame($client->exposeGetResumableUploadClient(), $clientProp->getValue($resumed));
    }
}
