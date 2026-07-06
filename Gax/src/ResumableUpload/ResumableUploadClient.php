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
use Google\ApiCore\RequestBuilder;
use Google\ApiCore\RetrySettings;
use Google\Protobuf\Internal\Message;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;

/**
 * Executes I/O operations (stream reading, HTTP requests) and manages retry loops for Resumable Uploads.
 */
class ResumableUploadClient
{
    private ResumableUploadSession $session;

    public function __construct(
        private $httpHandler,
        private RequestBuilder $requestBuilder,
        private string $serviceAddress,
        private string $uploadPrefix,
        private string $restPath,
        private Message $requestMessage,
        private array $initialHeaders = [],
        private ?RetrySettings $retrySettings = null,
        private int $chunkSize = 8388608, // 8MB
        private $progressCallback = null,
        private ?LoggerInterface $logger = null
    ) {
        $this->session = new ResumableUploadSession();
    }

    public function startUpload(StreamInterface $dataStream): bool
    {
        $event = new ResumableUploadEvent(type: ResumableUploadEvent::START_UPLOAD);
        $buffer = '';

        while (true) {
            $instruction = $this->session->processEvent($event);

            if ($instruction->action === ResumableUploadInstruction::TERMINATE_SUCCESS) {
                return true;
            }

            if ($instruction->action === ResumableUploadInstruction::TERMINATE_ERROR || $instruction->action === ResumableUploadInstruction::TERMINATE_REJECTED) {
                throw $instruction->exception ?? new ApiException('Upload terminated with error', 0, ApiStatus::INTERNAL);
            }

            if ($instruction->action === ResumableUploadInstruction::READ_STREAM) {
                $granularity = $this->session->getState()->chunkGranularity;
                $effectiveChunkSize = $this->chunkSize;
                if ($granularity > 0 && ($effectiveChunkSize % $granularity !== 0)) {
                    $effectiveChunkSize = (int) (floor($effectiveChunkSize / $granularity) * $granularity);
                    if ($effectiveChunkSize === 0) {
                        $effectiveChunkSize = $granularity;
                    }
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

            $url = $this->session->getState()->uploadUrl;
            $method = 'POST';
            $headers = [];
            $body = null;

            if ($instruction->action === ResumableUploadInstruction::SEND_START) {
                $url = rtrim($this->serviceAddress, '/') . '/' . ltrim($this->uploadPrefix, '/') . '/' . ltrim($this->restPath, '/');
                $headers = $this->initialHeaders;
                $headers['X-Goog-Upload-Command'] = 'start';
                $body = $this->requestMessage->serializeToJsonString();
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
                $request = new Request($method, $url, $headers, $body);
                $response = ($this->httpHandler)($request);
                if (is_object($response) && method_exists($response, 'wait')) {
                    $response = $response->wait();
                }

                $statusCode = $response->getStatusCode();
                $respHeaders = $response->getHeaders();
                $respBody = (string) $response->getBody();

                if ($this->progressCallback && ($instruction->action === ResumableUploadInstruction::SEND_UPLOAD || $instruction->action === ResumableUploadInstruction::SEND_UPLOAD_FINALIZE)) {
                    ($this->progressCallback)($this->session->getState()->committedOffset + strlen($buffer));
                }

                $event = new ResumableUploadEvent(
                    type: ResumableUploadEvent::HTTP_RESPONSE,
                    httpStatusCode: $statusCode,
                    headers: $respHeaders,
                    body: $respBody
                );
            } catch (RequestException $e) {
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
