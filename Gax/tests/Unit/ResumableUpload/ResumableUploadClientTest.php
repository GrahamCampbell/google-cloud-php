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

use Google\ApiCore\ApiException;
use Google\ApiCore\CredentialsWrapper;
use Google\ApiCore\ResumableUpload\ResumableUpload;
use Google\ApiCore\ResumableUpload\ResumableUploadClient;
use Google\Protobuf\Timestamp;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;

class ResumableUploadClientTest extends TestCase
{
    public function testIncludesRestTransportIfAlreadyUsed()
    {
        $transport = new TestTransport();
        $credentialsWrapper = $this->createMock(CredentialsWrapper::class);

        $client = new ResumableUploadClient(
            $transport->getHttpHandler(),
            $credentialsWrapper,
            serviceAddress: 'test.googleapis.com'
        );

        $ref = new \ReflectionClass($client);
        $this->assertIsCallable($ref->getProperty('httpHandler')->getValue($client));
        $this->assertSame($credentialsWrapper, $ref->getProperty('credentialsWrapper')->getValue($client));

        $upload = new ResumableUpload($client, 'v1/test:create', new Timestamp());
        $this->assertInstanceOf(ResumableUpload::class, $upload);

        $uploadRef = new \ReflectionClass($upload);
        $this->assertSame($client, $uploadRef->getProperty('resumableUploadClient')->getValue($upload));
    }
}
