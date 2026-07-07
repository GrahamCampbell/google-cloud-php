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
use Google\ApiCore\Transport\GrpcTransport;
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
            $transport,
            $credentialsWrapper,
            serviceAddress: 'test.googleapis.com'
        );

        $ref = new \ReflectionClass($client);
        $this->assertSame($transport, $ref->getProperty('transport')->getValue($client));
        $this->assertSame($credentialsWrapper, $ref->getProperty('credentialsWrapper')->getValue($client));

        $upload = new ResumableUpload($client, 'v1/test:create', new Timestamp());
        $this->assertInstanceOf(ResumableUpload::class, $upload);

        $uploadRef = new \ReflectionClass($upload);
        $this->assertSame($client, $uploadRef->getProperty('resumableUploadClient')->getValue($upload));
    }

    public function testWarningIssuedAndExceptionThrownIfCredentialsCannotBeFoundForGrpcTransport()
    {
        $transport = $this->createMock(GrpcTransport::class);

        $warningTriggered = false;
        set_error_handler(function (int $errno, string $errstr) use (&$warningTriggered) {
            if ($errno === E_USER_WARNING && strpos($errstr, 'Unable to find or load credentials for REST transport') !== false) {
                $warningTriggered = true;
                return true;
            }
            return false;
        });

        try {
            $client = new ResumableUploadClient(
                $transport,
                credentialsWrapper: null,
                credentials: null,
                serviceAddress: 'test.googleapis.com'
            );
        } finally {
            restore_error_handler();
        }

        $this->assertTrue($warningTriggered, 'Expected PHP warning when credentials cannot be found for gRPC transport.');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Unable to find or load credentials for REST transport');
        $client->startUpload(null, Utils::streamFor('hello'));
    }

    public function testExceptionThrownOnResumeUploadIfCredentialsMissing()
    {
        $transport = $this->createMock(GrpcTransport::class);

        set_error_handler(function () { return true; });
        try {
            $client = new ResumableUploadClient(
                $transport,
                credentialsWrapper: null,
                credentials: null,
                serviceAddress: 'test.googleapis.com'
            );
        } finally {
            restore_error_handler();
        }

        $upload = new ResumableUpload($client, '', null, ['uploadUrl' => 'https://upload.url/session123']);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Unable to find or load credentials for REST transport');
        $upload->startUpload(Utils::streamFor('hello'));
    }

    public function testWarningIssuedAndExceptionThrownIfChannelCredentialsPassedForGrpcTransport()
    {
        if (!class_exists(\Grpc\ChannelCredentials::class)) {
            $this->markTestSkipped('gRPC extension not available.');
        }

        $transport = $this->createMock(GrpcTransport::class);
        $credentialsWrapper = $this->createMock(CredentialsWrapper::class);
        $channelCredentials = \Grpc\ChannelCredentials::createSsl(null);

        $warningTriggered = false;
        set_error_handler(function (int $errno, string $errstr) use (&$warningTriggered) {
            if ($errno === E_USER_WARNING && strpos($errstr, 'Incompatible gRPC ChannelCredentials') !== false) {
                $warningTriggered = true;
                return true;
            }
            return false;
        });

        try {
            $client = new ResumableUploadClient(
                $transport,
                $credentialsWrapper,
                credentials: $channelCredentials,
                serviceAddress: 'test.googleapis.com'
            );
        } finally {
            restore_error_handler();
        }

        $this->assertTrue($warningTriggered, 'Expected PHP warning when ChannelCredentials passed for gRPC transport.');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Incompatible gRPC ChannelCredentials provided for ResumableUploadClient');
        $client->startUpload(null, Utils::streamFor('hello'));
    }
}
