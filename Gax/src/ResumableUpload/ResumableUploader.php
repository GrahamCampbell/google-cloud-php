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
use Google\ApiCore\RetrySettings;
use Google\ApiCore\Transport\TransportInterface;
use Google\Protobuf\Internal\Message;
use Psr\Http\Message\StreamInterface;

/**
 * User-facing helper object returned by generated client methods for Resumable Upload RPCs.
 * Supports both V1 (callable HTTP handler) and V2 (TransportInterface) generated clients.
 */
class ResumableUploader
{
    private ?int $chunkSize = null;
    private $progressCallback = null;
    private ?RetrySettings $retrySettings = null;
    private array $initialHeaders = [];

    public function __construct(
        private $transport,
        private ?CredentialsWrapper $credentialsWrapper = null,
        private array $agentHeader = [],
        private string $serviceAddress = '',
        private string $uploadPrefix = '/resumable/upload',
        private string $restPath = '',
        private ?Message $requestMessage = null,
        private array $options = [],
        private ?string $uploadUrl = null
    ) {
        $this->chunkSize = $options['chunkSize'] ?? 8388608;
        $this->progressCallback = $options['progressCallback'] ?? null;
        $this->retrySettings = $options['retrySettings'] ?? null;
        $this->initialHeaders = $options['headers'] ?? [];
        if (isset($options['uploadUrl'])) {
            $this->uploadUrl = $options['uploadUrl'];
        }
    }

    /**
     * Sets the preferred chunk size in bytes for the upload.
     */
    public function setChunkSize(int $chunkSize): self
    {
        $this->chunkSize = $chunkSize;
        return $this;
    }

    /**
     * Sets a callback function to be notified of upload progress.
     */
    public function setProgressCallback(callable $callback): self
    {
        $this->progressCallback = $callback;
        return $this;
    }

    /**
     * Starts the resumable upload exchange using the provided data stream.
     */
    public function startUpload(StreamInterface $dataStream): bool
    {
        $phase = $this->uploadUrl !== null ? 'RECOVERY' : 'STARTING';
        $uploadUrl = $this->uploadUrl;
        $committedOffset = 0;
        $chunkGranularity = 1;
        $recoveryAttempts = 0;
        $lastRecoveryOffset = -1;
        $previousPhase = 'STARTING';

        $buffer = '';
        $hasBuffer = false;
        $isEof = false;

        while (true) {
            if ($phase === 'DONE') {
                return true;
            }

            if ($phase === 'STARTING') {
                $baseUri = $this->serviceAddress;
                if (!str_starts_with($baseUri, 'http://') && !str_starts_with($baseUri, 'https://')) {
                    $baseUri = 'https://' . $baseUri;
                }
                $url = rtrim($baseUri, '/') . '/' . ltrim($this->uploadPrefix, '/') . '/' . ltrim($this->restPath, '/');
                $headers = $this->initialHeaders;
                $headers['X-Goog-Upload-Command'] = 'start';
                $body = $this->requestMessage ? $this->requestMessage->serializeToJsonString() : '';

                try {
                    $response = $this->sendHttpRequest('POST', $url, $headers, $body);
                    $statusCode = $response->getStatusCode();
                    if ($statusCode === 200) {
                        $uploadUrl = $this->getHeaderCaseInsensitive($response->getHeaders(), 'X-Goog-Upload-URL') ?? $uploadUrl;
                        $chunkGranularity = (int) ($this->getHeaderCaseInsensitive($response->getHeaders(), 'X-Goog-Upload-Chunk-Granularity') ?? 1);
                        $phase = 'TRANSMITTING';
                    } else {
                        $this->handleErrorResponse($response);
                    }
                } catch (\Exception $e) {
                    $phase = $this->handleException($e, $phase, $committedOffset, $recoveryAttempts, $lastRecoveryOffset);
                }
                continue;
            }

            if ($phase === 'TRANSMITTING' || $phase === 'FINALIZING') {
                if (!$hasBuffer) {
                    $effectiveChunkSize = $this->chunkSize ?? 8388608;
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
                    $phase = 'FINALIZING';
                    if (strlen($buffer) > 0) {
                        $headers['X-Goog-Upload-Command'] = 'upload, finalize';
                        $body = $buffer;
                    } else {
                        $headers['X-Goog-Upload-Command'] = 'finalize';
                        $body = '';
                    }
                } else {
                    $phase = 'TRANSMITTING';
                    $headers['X-Goog-Upload-Command'] = 'upload';
                    $body = $buffer;
                }

                try {
                    $response = $this->sendHttpRequest('POST', $uploadUrl, $headers, $body);
                    $statusCode = $response->getStatusCode();
                    if ($statusCode === 200) {
                        if ($this->progressCallback && $headers['X-Goog-Upload-Command'] !== 'finalize') {
                            ($this->progressCallback)($committedOffset + strlen($buffer));
                        }

                        $statusHeader = $this->getHeaderCaseInsensitive($response->getHeaders(), 'X-Goog-Upload-Status');
                        if (strcasecmp((string) $statusHeader, 'final') === 0) {
                            $phase = 'DONE';
                        } else {
                            $committedOffset += strlen($buffer);
                            $hasBuffer = false;
                            $phase = 'TRANSMITTING';
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

            if ($phase === 'RECOVERY') {
                $headers = ['X-Goog-Upload-Command' => 'query'];
                try {
                    $response = $this->sendHttpRequest('POST', $uploadUrl, $headers, '');
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

                        $phase = $previousPhase === 'FINALIZING' ? 'FINALIZING' : 'TRANSMITTING';
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
            return 'RECOVERY';
        }

        if ($e instanceof ApiException) {
            throw $e;
        }
        throw new ApiException(
            $e->getMessage(),
            $code,
            ApiStatus::INTERNAL,
            $e
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
            new \GuzzleHttp\Psr7\Request('POST', ''),
            $response
        );
    }
}
