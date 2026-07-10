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

use Exception;
use Google\ApiCore\ApiException;
use Google\ApiCore\ApiStatus;
use Google\ApiCore\CredentialsWrapper;
use Google\ApiCore\RequestBuilder;
use Google\Protobuf\Internal\Message;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
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
    private const DEFAULT_CHUNK_SIZE = 8388608;
    private const MAX_RECOVERY_ATTEMPTS = 3;

    /** @var callable|null */
    private $httpHandler = null;
    private ?CredentialsWrapper $credentialsWrapper = null;
    private array $agentHeader = [];
    private string $serviceAddress = '';
    private string $uploadPrefix = '/resumable/upload';
    private RequestBuilder $requestBuilder;

    /**
     * @param RequestBuilder $requestBuilder RequestBuilder for rendering REST URI templates and wildcards.
     * @param callable $httpHandler Handler used to deliver PSR-7 requests.
     * @param ?CredentialsWrapper $credentialsWrapper The credentials wrapper from GAPIC client.
     * @param array $agentHeader Agent header array.
     * @param string $serviceAddress Service address or API endpoint.
     * @param string $uploadPrefix Resumable upload path prefix (default: '/resumable/upload').
     */
    public function __construct(
        RequestBuilder $requestBuilder,
        callable $httpHandler,
        ?CredentialsWrapper $credentialsWrapper = null,
        array $agentHeader = [],
        string $serviceAddress = '',
        string $uploadPrefix = '/resumable/upload'
    ) {
        $this->requestBuilder = $requestBuilder;
        $this->httpHandler = $httpHandler;
        $this->credentialsWrapper = $credentialsWrapper;
        $this->agentHeader = $agentHeader;
        $this->serviceAddress = $serviceAddress;
        $this->uploadPrefix = $uploadPrefix;
    }

    /**
     * Starts the resumable upload exchange using the provided data stream.
     *
     * @param ResumableUpload $upload
     * @param StreamInterface $dataStream
     * @param string $method
     * @param ?Message $requestMessage
     * @param array $options
     * @return bool
     * @throws ApiException
     */
    public function startUpload(
        ResumableUpload $upload,
        StreamInterface $dataStream,
        string $method = '',
        ?Message $requestMessage = null,
        array $options = []
    ): bool {
        $uploadUrl = $options['uploadUrl'] ?? null;
        $state = new ResumableUploadState(
            $options['chunkSize'] ?? self::DEFAULT_CHUNK_SIZE,
            $options['progressCallback'] ?? null,
            $options['headers'] ?? [],
            $uploadUrl,
            $uploadUrl !== null ? self::PHASE_RECOVERY : self::PHASE_STARTING
        );

        while ($state->phase !== self::PHASE_DONE) {
            $state->phase = match ($state->phase) {
                self::PHASE_STARTING => $this->phaseStarting(
                    $state,
                    $upload,
                    $dataStream,
                    $method,
                    $requestMessage
                ),
                self::PHASE_TRANSMITTING => $this->phaseTransmitting($state, $dataStream),
                self::PHASE_FINALIZING => $this->phaseFinalizing($state, $dataStream),
                self::PHASE_RECOVERY => $this->phaseRecovery($state),
                default => throw new ApiException("Unexpected phase: {$state->phase}", 0, ApiStatus::INTERNAL),
            };
        }

        return true;
    }

    private function phaseStarting(
        ResumableUploadState $state,
        ResumableUpload $upload,
        StreamInterface $dataStream,
        string $method,
        ?Message $requestMessage
    ): string {
        $headers = $state->headers;
        $headers['X-Goog-Upload-Protocol'] = 'resumable';
        $headers['X-Goog-Upload-Command'] = 'start';
        if ($dataStream->getSize() !== null) {
            $headers['X-Goog-Upload-Header-Content-Length'] = (string) $dataStream->getSize();
        }

        $requestMessage = $requestMessage ?: new \Google\Protobuf\Internal\GPBEmpty();
        $request = $this->requestBuilder->build($method, $requestMessage, $headers);
        if ($this->uploadPrefix !== '' && $this->uploadPrefix !== '/') {
            $uri = $request->getUri();
            $path = $uri->getPath();
            $newPath = rtrim($this->uploadPrefix, '/') . ($path === '' || $path === '/' ? '' : '/' . ltrim($path, '/'));
            $request = $request->withUri($uri->withPath($newPath));
        }

        try {
            $response = $this->sendRequest($request);
            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                $this->handleErrorResponse($response);
            }
            $urlHeader = $response->getHeaderLine('X-Goog-Upload-URL');
            if (!empty($urlHeader)) {
                $state->uploadUrl = $urlHeader;
            }
            if ($state->uploadUrl !== null) {
                $upload->setUploadUrl($state->uploadUrl);
            }
            $granularityHeader = $response->getHeaderLine('X-Goog-Upload-Chunk-Granularity');
            $state->chunkGranularity = !empty($granularityHeader) ? (int) $granularityHeader : 1;
            return self::PHASE_TRANSMITTING;
        } catch (Exception $e) {
            return $this->handleException(
                $e,
                self::PHASE_STARTING,
                $state->committedOffset,
                $state->recoveryAttempts,
                $state->lastRecoveryOffset
            );
        }
    }

    private function phaseTransmitting(
        ResumableUploadState $state,
        StreamInterface $dataStream
    ): string {
        return $this->phaseTransmittingOrFinalizing(self::PHASE_TRANSMITTING, $state, $dataStream);
    }

    private function phaseFinalizing(
        ResumableUploadState $state,
        StreamInterface $dataStream
    ): string {
        return $this->phaseTransmittingOrFinalizing(self::PHASE_FINALIZING, $state, $dataStream);
    }

    private function phaseTransmittingOrFinalizing(
        string $phase,
        ResumableUploadState $state,
        StreamInterface $dataStream
    ): string {
        if ($state->buffer === null) {
            $effectiveChunkSize = $state->chunkSize;
            if ($state->chunkGranularity > 0 && ($effectiveChunkSize % $state->chunkGranularity !== 0)) {
                $effectiveChunkSize = (int) (
                    floor($effectiveChunkSize / $state->chunkGranularity) * $state->chunkGranularity
                );
                if ($effectiveChunkSize === 0) {
                    $effectiveChunkSize = $state->chunkGranularity;
                }
            }

            if ($state->committedOffset > 0 && $dataStream->tell() !== $state->committedOffset) {
                $dataStream->seek($state->committedOffset);
            }

            $state->buffer = $dataStream->read($effectiveChunkSize);
            $state->isEof = $dataStream->eof();
        }

        $headers = [];
        $headers['X-Goog-Upload-Offset'] = (string) $state->committedOffset;

        if ($state->isEof) {
            $phase = self::PHASE_FINALIZING;
            if (strlen((string) $state->buffer) > 0) {
                $headers['X-Goog-Upload-Command'] = 'upload, finalize';
                $body = (string) $state->buffer;
            } else {
                $headers['X-Goog-Upload-Command'] = 'finalize';
                $body = '';
            }
        } else {
            $phase = self::PHASE_TRANSMITTING;
            $headers['X-Goog-Upload-Command'] = 'upload';
            $body = (string) $state->buffer;
        }

        try {
            $response = $this->sendRequest(
                new Request('POST', (string) $state->uploadUrl, $headers, $body)
            );
            $statusCode = $response->getStatusCode();
            if ($statusCode === 200) {
                if ($state->progressCallback && $headers['X-Goog-Upload-Command'] !== 'finalize') {
                    ($state->progressCallback)(
                        $state->committedOffset + strlen((string) $state->buffer),
                        (string) $state->uploadUrl
                    );
                }

                $statusHeader = $response->getHeaderLine('X-Goog-Upload-Status');
                if ($statusHeader === 'final') {
                    return self::PHASE_DONE;
                }
                $state->committedOffset += strlen((string) $state->buffer);
                $state->buffer = null;
                return self::PHASE_TRANSMITTING;
            }
            $this->handleErrorResponse($response);
        } catch (Exception $e) {
            $state->previousPhase = $phase;
            return $this->handleException(
                $e,
                $phase,
                $state->committedOffset,
                $state->recoveryAttempts,
                $state->lastRecoveryOffset
            );
        }

        return $phase;
    }

    private function phaseRecovery(ResumableUploadState $state): string
    {
        $headers = ['X-Goog-Upload-Command' => 'query'];
        try {
            $response = $this->sendRequest(
                new Request('POST', (string) $state->uploadUrl, $headers, '')
            );
            $statusCode = $response->getStatusCode();
            if ($statusCode === 200) {
                $serverOffsetStr = $response->getHeaderLine('X-Goog-Upload-Size-Received');
                $serverOffset = !empty($serverOffsetStr) || $serverOffsetStr === '0'
                    ? (int) $serverOffsetStr
                    : $state->committedOffset;

                if ($serverOffset === $state->lastRecoveryOffset) {
                    $state->recoveryAttempts++;
                    if ($state->recoveryAttempts >= self::MAX_RECOVERY_ATTEMPTS) {
                        throw new ApiException(
                            'Exhausted recovery attempts with unchanged offset',
                            0,
                            ApiStatus::ABORTED
                        );
                    }
                } else {
                    $state->recoveryAttempts = 0;
                }
                $state->lastRecoveryOffset = $serverOffset;
                $state->committedOffset = $serverOffset;
                $state->buffer = null;

                return $state->previousPhase === self::PHASE_FINALIZING
                    ? self::PHASE_FINALIZING
                    : self::PHASE_TRANSMITTING;
            }
            $this->handleErrorResponse($response);
        } catch (Exception $e) {
            return $this->handleException(
                $e,
                self::PHASE_RECOVERY,
                $state->committedOffset,
                $state->recoveryAttempts,
                $state->lastRecoveryOffset
            );
        }

        return self::PHASE_RECOVERY;
    }

    private function sendRequest(RequestInterface $request): ResponseInterface
    {
        $reqHeaders = array_merge($this->agentHeader, $request->getHeaders());
        if ($this->credentialsWrapper) {
            $reqHeaders = $this->credentialsWrapper->addCredentialsToRequestHeaders($reqHeaders);
        }
        foreach ($reqHeaders as $k => $v) {
            $request = $request->withHeader($k, $v);
        }

        $httpHandler = $this->httpHandler;
        $response = $httpHandler($request);
        if (is_object($response) && method_exists($response, 'wait')) {
            $response = $response->wait();
        }
        return $response;
    }

    private function handleException(
        Exception $e,
        string $phase,
        int $committedOffset,
        int &$recoveryAttempts,
        int $lastRecoveryOffset
    ): string {
        $code = $e->getCode();
        if ($e instanceof RequestException) {
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

    private function handleErrorResponse(ResponseInterface $response)
    {
        $statusCode = $response->getStatusCode();
        $body = (string) $response->getBody();

        $statusHeader = $response->getHeaderLine('X-Goog-Upload-Status');
        if ($statusHeader === 'final') {
            throw new ApiException(
                $body ?: 'Upload rejected by server',
                $statusCode,
                ApiStatus::INVALID_ARGUMENT
            );
        }

        throw new RequestException(
            "HTTP error {$statusCode}",
            new Request('POST', ''),
            $response
        );
    }
}
