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

namespace Google\ApiCore\ResumableUpload;

use Google\ApiCore\ApiException;
use Google\ApiCore\ApiStatus;
use Google\ApiCore\CredentialsWrapper;
use Google\ApiCore\Transport\GrpcFallbackTransport;
use Google\ApiCore\Transport\RestTransport;
use Google\ApiCore\Transport\TransportInterface;
use Google\Protobuf\Internal\Message;
use Psr\Http\Message\StreamInterface;

/**
 * Manages the REST transport and authentication credentials for resumable upload RPCs,
 * and executes the HTTP upload stream loop.
 * Instantiated during GAPIC client initialization when the service has resumable upload methods.
 */
class ResumableUploadClient
{
    private const PHASE_STARTING = 'STARTING';
    private const PHASE_TRANSMITTING = 'TRANSMITTING';
    private const PHASE_FINALIZING = 'FINALIZING';
    private const PHASE_RECOVERY = 'RECOVERY';
    private const PHASE_DONE = 'DONE';
    private const METHOD_POST = 'POST';

    private ?TransportInterface $transport = null;
    private ?CredentialsWrapper $credentialsWrapper = null;
    private array $agentHeader = [];
    private string $serviceAddress = '';
    private string $uploadPrefix = '/resumable/upload';
    private ?ApiException $missingCredentialsException = null;

    /**
     * @param TransportInterface|mixed $transport The main GAPIC transport (REST, gRPC, or fallback).
     * @param ?CredentialsWrapper $credentialsWrapper The credentials wrapper from the main GAPIC client.
     * @param mixed $credentials Raw credentials option passed during client initialization.
     * @param array $transportConfig Transport config option passed during client initialization.
     * @param array $agentHeader Agent header array.
     * @param string $serviceAddress Service address or API endpoint.
     * @param string $uploadPrefix Resumable upload path prefix (default: '/resumable/upload').
     */
    public function __construct(
        $transport,
        ?CredentialsWrapper $credentialsWrapper = null,
        $credentials = null,
        array $transportConfig = [],
        array $agentHeader = [],
        string $serviceAddress = '',
        string $uploadPrefix = '/resumable/upload'
    ) {
        $this->agentHeader = $agentHeader;
        $this->serviceAddress = $serviceAddress;
        $this->uploadPrefix = $uploadPrefix;

        if ($transport instanceof RestTransport || $transport instanceof GrpcFallbackTransport || (is_object($transport) && method_exists($transport, 'sendRequest')) || is_callable($transport)) {
            $this->transport = $transport instanceof TransportInterface ? $transport : null;
            if ($transport instanceof RestTransport || $transport instanceof GrpcFallbackTransport || (is_object($transport) && method_exists($transport, 'sendRequest'))) {
                $this->transport = $transport; // @phpstan-ignore-line
            }
            $this->credentialsWrapper = $credentialsWrapper;
            return;
        }

        // We are dealing with a gRPC transport (e.g. GrpcTransport or string 'grpc').
        // We must create a new RestTransport using credentials from the gRPC transport.
        $this->credentialsWrapper = $credentialsWrapper;
        $restConfig = $transportConfig['rest'] ?? [];

        // Check if valid credentials exist or can be derived for REST
        if ($this->credentialsWrapper === null) {
            $this->issueWarningAndRecordException('Unable to find or load credentials for REST transport required by ResumableUploadClient.');
            return;
        }

        // Check if the credentials object is a gRPC ChannelCredentials (which cannot be used by REST)
        if ($credentials instanceof \Grpc\ChannelCredentials) {
            $this->issueWarningAndRecordException('Incompatible gRPC ChannelCredentials provided for ResumableUploadClient; REST credentials required.');
            return;
        }

        if (!isset($restConfig['restClientConfigPath']) || !file_exists($restConfig['restClientConfigPath'])) {
            $this->issueWarningAndRecordException("The 'restClientConfigPath' config is missing or file does not exist for ResumableUploadClient.");
            return;
        }

        try {
            $this->transport = RestTransport::build($this->serviceAddress, $restConfig['restClientConfigPath'], $restConfig);
        } catch (\Exception $ex) {
            $this->issueWarningAndRecordException('Failed building RestTransport for ResumableUploadClient: ' . $ex->getMessage());
        }
    }

    private function issueWarningAndRecordException(string $message): void
    {
        trigger_error($message, E_USER_WARNING);
        $this->missingCredentialsException = new ApiException($message, 0, ApiStatus::UNAUTHENTICATED);
    }

    /**
     * Starts the resumable upload exchange using the provided data stream.
     *
     * @param ResumableUpload $upload
     * @param StreamInterface $dataStream
     * @param string $restPath
     * @param ?Message $requestMessage
     * @param array $options
     * @return bool
     * @throws ApiException
     */
    public function startUpload(
        ?ResumableUpload $upload,
        StreamInterface $dataStream,
        string $restPath = '',
        ?Message $requestMessage = null,
        array $options = []
    ): bool {
        if ($this->missingCredentialsException !== null) {
            throw $this->missingCredentialsException;
        }
        if ($this->transport === null) {
            throw new ApiException('Resumable uploads require a valid REST transport.', 0, ApiStatus::UNIMPLEMENTED);
        }

        $uploadUrl = $options['uploadUrl'] ?? null;
        $chunkSize = $options['chunkSize'] ?? 8388608;
        $progressCallback = $options['progressCallback'] ?? null;
        $initialHeaders = $options['headers'] ?? [];

        $phase = $uploadUrl !== null ? self::PHASE_RECOVERY : self::PHASE_STARTING;
        $committedOffset = 0;
        $chunkGranularity = 1;
        $recoveryAttempts = 0;
        $lastRecoveryOffset = -1;
        $previousPhase = self::PHASE_STARTING;

        $buffer = '';
        $hasBuffer = false;
        $isEof = false;

        while (true) {
            if ($phase === self::PHASE_DONE) {
                return true;
            }

            if ($phase === self::PHASE_STARTING) {
                $baseUri = $this->serviceAddress;
                if (!str_starts_with($baseUri, 'http://') && !str_starts_with($baseUri, 'https://')) {
                    $baseUri = 'https://' . $baseUri;
                }
                $url = rtrim($baseUri, '/') . '/' . ltrim($this->uploadPrefix, '/') . '/' . ltrim($restPath, '/');
                $headers = $initialHeaders;
                $headers['X-Goog-Upload-Command'] = 'start';
                $body = $requestMessage ? $requestMessage->serializeToJsonString() : '';

                try {
                    $response = $this->sendHttpRequest(self::METHOD_POST, $url, $headers, $body);
                    $statusCode = $response->getStatusCode();
                    if ($statusCode === 200) {
                        $uploadUrl = $this->getHeaderCaseInsensitive($response->getHeaders(), 'X-Goog-Upload-URL') ?? $uploadUrl;
                        if ($upload !== null && $uploadUrl !== null) {
                            $upload->setUploadUrl($uploadUrl);
                        }
                        $chunkGranularity = (int) ($this->getHeaderCaseInsensitive($response->getHeaders(), 'X-Goog-Upload-Chunk-Granularity') ?? 1);
                        $phase = self::PHASE_TRANSMITTING;
                    } else {
                        $this->handleErrorResponse($response);
                    }
                } catch (\Exception $e) {
                    $phase = $this->handleException($e, $phase, $committedOffset, $recoveryAttempts, $lastRecoveryOffset);
                }
                continue;
            }

            if ($phase === self::PHASE_TRANSMITTING || $phase === self::PHASE_FINALIZING) {
                if (!$hasBuffer) {
                    $effectiveChunkSize = $chunkSize ?? 8388608;
                    if ($chunkGranularity > 0 && ($effectiveChunkSize % $chunkGranularity !== 0)) {
                        $effectiveChunkSize = (int) (floor($effectiveChunkSize / $chunkGranularity) * $chunkGranularity);
                        if ($effectiveChunkSize === 0) {
                            $effectiveChunkSize = $chunkGranularity;
                        }
                    }

                    if ($committedOffset > 0 && $dataStream->tell() !== $committedOffset) {
                        $dataStream->seek($committedOffset);
                    }

                    $buffer = $dataStream->read($effectiveChunkSize);
                    $isEof = $dataStream->eof();
                    $hasBuffer = true;
                }

                $headers = [];
                $headers['X-Goog-Upload-Offset'] = (string) $committedOffset;

                if ($isEof) {
                    $phase = self::PHASE_FINALIZING;
                    if (strlen($buffer) > 0) {
                        $headers['X-Goog-Upload-Command'] = 'upload, finalize';
                        $body = $buffer;
                    } else {
                        $headers['X-Goog-Upload-Command'] = 'finalize';
                        $body = '';
                    }
                } else {
                    $phase = self::PHASE_TRANSMITTING;
                    $headers['X-Goog-Upload-Command'] = 'upload';
                    $body = $buffer;
                }

                try {
                    $response = $this->sendHttpRequest(self::METHOD_POST, (string) $uploadUrl, $headers, $body);
                    $statusCode = $response->getStatusCode();
                    if ($statusCode === 200) {
                        if ($progressCallback && $headers['X-Goog-Upload-Command'] !== 'finalize') {
                            ($progressCallback)($committedOffset + strlen($buffer), (string) $uploadUrl);
                        }

                        $statusHeader = $this->getHeaderCaseInsensitive($response->getHeaders(), 'X-Goog-Upload-Status');
                        if (strcasecmp((string) $statusHeader, 'final') === 0) {
                            $phase = self::PHASE_DONE;
                        } else {
                            $committedOffset += strlen($buffer);
                            $hasBuffer = false;
                            $phase = self::PHASE_TRANSMITTING;
                        }
                    } else {
                        $this->handleErrorResponse($response);
                    }
                } catch (\Exception $e) {
                    $previousPhase = $phase;
                    $phase = $this->handleException($e, $phase, $committedOffset, $recoveryAttempts, $lastRecoveryOffset);
                }
                continue;
            }

            if ($phase === self::PHASE_RECOVERY) {
                $headers = ['X-Goog-Upload-Command' => 'query'];
                try {
                    $response = $this->sendHttpRequest(self::METHOD_POST, (string) $uploadUrl, $headers, '');
                    $statusCode = $response->getStatusCode();
                    if ($statusCode === 200) {
                        $serverOffsetStr = $this->getHeaderCaseInsensitive($response->getHeaders(), 'X-Goog-Upload-Size-Received');
                        $serverOffset = $serverOffsetStr !== null ? (int) $serverOffsetStr : $committedOffset;

                        if ($serverOffset === $lastRecoveryOffset) {
                            $recoveryAttempts++;
                            if ($recoveryAttempts >= 3) {
                                throw new ApiException('Exhausted recovery attempts with unchanged offset', 0, ApiStatus::ABORTED);
                            }
                        } else {
                            $recoveryAttempts = 0;
                        }
                        $lastRecoveryOffset = $serverOffset;
                        $committedOffset = $serverOffset;
                        $hasBuffer = false;

                        $phase = $previousPhase === self::PHASE_FINALIZING ? self::PHASE_FINALIZING : self::PHASE_TRANSMITTING;
                    } else {
                        $this->handleErrorResponse($response);
                    }
                } catch (\Exception $e) {
                    $phase = $this->handleException($e, $phase, $committedOffset, $recoveryAttempts, $lastRecoveryOffset);
                }
                continue;
            }

            throw new ApiException("Unexpected phase: {$phase}", 0, ApiStatus::INTERNAL);
        }
    }

    private function sendHttpRequest(string $method, string $url, array $headers, $body): \Psr\Http\Message\ResponseInterface
    {
        $reqHeaders = array_merge($this->agentHeader, $headers);
        if ($this->credentialsWrapper) {
            $reqHeaders = $this->credentialsWrapper->addCredentialsToRequestHeaders($reqHeaders);
        }

        $request = new \GuzzleHttp\Psr7\Request($method, $url, $reqHeaders, $body);

        if ($this->transport instanceof TransportInterface) {
            if (!method_exists($this->transport, 'sendRequest')) {
                throw new ApiException('Resumable uploads require REST transport.', 0, ApiStatus::UNIMPLEMENTED);
            }
            $promise = $this->transport->sendRequest($request);
            return $promise->wait();
        } else {
            $response = ($this->transport)($request);
            if (is_object($response) && method_exists($response, 'wait')) {
                $response = $response->wait();
            }
            return $response;
        }
    }

    private function getHeaderCaseInsensitive(array $headers, string $key): ?string
    {
        foreach ($headers as $k => $v) {
            if (strcasecmp((string) $k, $key) === 0) {
                return is_array($v) ? (string) reset($v) : (string) $v;
            }
        }
        return null;
    }

    private function handleException(\Exception $e, string $phase, int $committedOffset, int &$recoveryAttempts, int $lastRecoveryOffset): string
    {
        $code = $e->getCode();
        if ($e instanceof \GuzzleHttp\Exception\RequestException) {
            $response = $e->getResponse();
            if ($response) {
                $code = $response->getStatusCode();
            }
        }

        if (in_array($code, [429, 500, 502, 503, 504])) {
            return $phase;
        }

        if (in_array($code, [400, 412, 416])) {
            return self::PHASE_RECOVERY;
        }

        if ($e instanceof ApiException) {
            throw $e;
        }
        throw new ApiException(
            $e->getMessage(),
            $code,
            ApiStatus::INTERNAL,
            ['previous' => $e]
        );
    }

    private function handleErrorResponse(\Psr\Http\Message\ResponseInterface $response)
    {
        $statusCode = $response->getStatusCode();
        $headers = $response->getHeaders();
        $body = (string) $response->getBody();

        $statusHeader = $this->getHeaderCaseInsensitive($headers, 'X-Goog-Upload-Status');
        if (strcasecmp((string) $statusHeader, 'final') === 0) {
            throw new ApiException(
                $body ?: 'Upload rejected by server',
                $statusCode,
                ApiStatus::INVALID_ARGUMENT
            );
        }

        throw new \GuzzleHttp\Exception\RequestException(
            "HTTP error {$statusCode}",
            new \GuzzleHttp\Psr7\Request(self::METHOD_POST, ''),
            $response
        );
    }
}
