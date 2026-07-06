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
 */
class ResumableUploader
{
    private ?int $chunkSize = null;
    private $progressCallback = null;

    public function __construct(
        private TransportInterface $transport,
        private ?CredentialsWrapper $credentialsWrapper = null,
        private array $agentHeader = [],
        private string $serviceAddress = '',
        private string $uploadPrefix = '/resumable/upload',
        private string $restPath = '',
        private ?Message $requestMessage = null,
        private array $initialHeaders = [],
        private ?RetrySettings $retrySettings = null,
        private ?string $uploadUrl = null,
        int $chunkSize = 8388608
    ) {
        $this->chunkSize = $chunkSize;
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
        $initialState = null;
        if ($this->uploadUrl !== null) {
            $initialState = new ResumableUploadState(
                phase: ResumableUploadState::RECOVERY,
                uploadUrl: $this->uploadUrl
            );
        }

        $session = new ResumableUploadSession($initialState);
        $event = new ResumableUploadEvent(type: ResumableUploadEvent::START_UPLOAD);
        $buffer = '';

        while (true) {
            $instruction = $session->processEvent($event);

            if ($instruction->action === ResumableUploadInstruction::TERMINATE_SUCCESS) {
                return true;
            }

            if ($instruction->action === ResumableUploadInstruction::TERMINATE_ERROR || $instruction->action === ResumableUploadInstruction::TERMINATE_REJECTED) {
                throw $instruction->exception ?? new ApiException('Upload terminated with error', 0, ApiStatus::INTERNAL);
            }

            if ($instruction->action === ResumableUploadInstruction::READ_STREAM) {
                $granularity = $session->getState()->chunkGranularity;
                $effectiveChunkSize = $this->chunkSize ?? 8388608;
                if ($granularity > 0 && ($effectiveChunkSize % $granularity !== 0)) {
                    $effectiveChunkSize = (int) (floor($effectiveChunkSize / $granularity) * $granularity);
                    if ($effectiveChunkSize === 0) {
                        $effectiveChunkSize = $granularity;
                    }
                }

                $committedOffset = $session->getState()->committedOffset;
                if ($committedOffset > 0 && $dataStream->tell() !== $committedOffset) {
                    $dataStream->seek($committedOffset);
                }

                $buffer = $dataStream->read($effectiveChunkSize);
                $isEof = $dataStream->eof();

                $event = new ResumableUploadEvent(
                    type: $isEof ? ResumableUploadEvent::EOF_REACHED : ResumableUploadEvent::CHUNK_READ,
                    bytesRead: strlen($buffer),
                    isEof: $isEof,
                    body: $buffer
                );
                continue;
            }

            $url = $session->getState()->uploadUrl ?? '';
            $method = 'POST';
            $headers = [];
            $body = null;

            if ($instruction->action === ResumableUploadInstruction::SEND_START) {
                $baseUri = $this->serviceAddress;
                if (!str_starts_with($baseUri, 'http://') && !str_starts_with($baseUri, 'https://')) {
                    $baseUri = 'https://' . $baseUri;
                }
                $url = rtrim($baseUri, '/') . '/' . ltrim($this->uploadPrefix, '/') . '/' . ltrim($this->restPath, '/');
                $headers = $this->initialHeaders;
                $headers['X-Goog-Upload-Command'] = 'start';
                $body = $this->requestMessage ? $this->requestMessage->serializeToJsonString() : '';
            } else {
                if ($instruction->commandHeader !== null) {
                    $headers['X-Goog-Upload-Command'] = $instruction->commandHeader;
                }
                if ($instruction->offsetHeader !== null) {
                    $headers['X-Goog-Upload-Offset'] = (string) $instruction->offsetHeader;
                }
                if ($instruction->action === ResumableUploadInstruction::SEND_UPLOAD || $instruction->action === ResumableUploadInstruction::SEND_UPLOAD_FINALIZE) {
                    $body = $buffer;
                }
            }

            try {
                $reqHeaders = array_merge($this->agentHeader, $headers);
                if ($this->credentialsWrapper) {
                    $reqHeaders = $this->credentialsWrapper->addCredentialsToRequestHeaders($reqHeaders);
                }

                $request = new \GuzzleHttp\Psr7\Request($method, $url, $reqHeaders, $body);
                if (!method_exists($this->transport, 'sendRequest')) {
                    throw new ApiException('Resumable uploads require REST transport.', 0, ApiStatus::UNIMPLEMENTED);
                }
                $promise = $this->transport->sendRequest($request);
                $response = $promise->wait();

                $statusCode = $response->getStatusCode();
                $respHeaders = $response->getHeaders();
                $respBody = (string) $response->getBody();

                if ($this->progressCallback && ($instruction->action === ResumableUploadInstruction::SEND_UPLOAD || $instruction->action === ResumableUploadInstruction::SEND_UPLOAD_FINALIZE)) {
                    ($this->progressCallback)($session->getState()->committedOffset + strlen($buffer));
                }

                $event = new ResumableUploadEvent(
                    type: ResumableUploadEvent::HTTP_RESPONSE,
                    httpStatusCode: $statusCode,
                    headers: $respHeaders,
                    body: $respBody
                );
            } catch (\GuzzleHttp\Exception\RequestException $e) {
                $code = $e->getCode();
                if (in_array($code, [429, 500, 502, 503, 504])) {
                    $event = new ResumableUploadEvent(type: ResumableUploadEvent::ERROR_TRANSIENT, exception: $e);
                } elseif (in_array($code, [400, 412, 416])) {
                    $event = new ResumableUploadEvent(type: ResumableUploadEvent::ERROR_RECOVERABLE, exception: $e);
                } else {
                    $event = new ResumableUploadEvent(type: ResumableUploadEvent::ERROR_FATAL, exception: $e);
                }
            } catch (\Exception $e) {
                $event = new ResumableUploadEvent(type: ResumableUploadEvent::ERROR_FATAL, exception: $e);
            }
        }
    }
}


