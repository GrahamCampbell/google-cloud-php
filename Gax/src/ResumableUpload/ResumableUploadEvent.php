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

/**
 * Represents an event triggered by I/O completion or user interaction in a resumable upload session.
 */
class ResumableUploadEvent
{
    public const START_UPLOAD = 'START_UPLOAD';
    public const CHUNK_READ = 'CHUNK_READ';
    public const EOF_REACHED = 'EOF_REACHED';
    public const HTTP_RESPONSE = 'HTTP_RESPONSE';
    public const ERROR_TRANSIENT = 'ERROR_TRANSIENT'; // Category 1
    public const ERROR_RECOVERABLE = 'ERROR_RECOVERABLE'; // Category 2
    public const ERROR_FATAL = 'ERROR_FATAL'; // Category 3
    public const CANCEL_REQUESTED = 'CANCEL_REQUESTED';

    public function __construct(
        public string $type,
        public int $httpStatusCode = 0,
        public array $headers = [],
        public ?string $body = null,
        public int $bytesRead = 0,
        public bool $isEof = false,
        public ?Exception $exception = null
    ) {}
}
