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

/**
 * Trait for GAPIC clients that support resumable uploads.
 */
trait ResumableUploadTrait
{
    private ?ResumableUploadClient $resumableUploadClient = null;

    /**
     * Resume an existing resumable upload session.
     *
     * @param string $uploadUrl The resumable upload session URL.
     * @param int $chunkSize Optional. The preferred chunk size in bytes.
     * @return ResumableUpload
     */
    public function resumeUpload(string $uploadUrl, int $chunkSize = 8388608): ResumableUpload
    {
        return new ResumableUpload($this->getResumableUploadClient(), '', null, ['uploadUrl' => $uploadUrl, 'chunkSize' => $chunkSize]);
    }

    /**
     * Get the ResumableUploadClient for this GAPIC client.
     *
     * @access private
     * @return ResumableUploadClient
     * @throws ApiException
     */
    protected function getResumableUploadClient(): ResumableUploadClient
    {
        if ($this->resumableUploadClient === null) {
            throw new ApiException('Resumable uploads are not supported or configured on this client.', 0, ApiStatus::UNIMPLEMENTED);
        }
        return $this->resumableUploadClient;
    }

    /**
     * Create the ResumableUploadClient for this GAPIC client.
     *
     * @param array $options
     * @return ResumableUploadClient
     */
    private function createResumableUploadClient(array $options): ResumableUploadClient
    {
        $credentialsWrapper = $this->credentialsWrapper instanceof CredentialsWrapper
            ? $this->credentialsWrapper
            : null;

        return new ResumableUploadClient(
            $this->transport,
            $credentialsWrapper,
            $options['credentials'] ?? null,
            $options['transportConfig'] ?? [],
            $this->agentHeader,
            $this->apiEndpoint,
            $options['uploadPrefix'] ?? '/resumable/upload'
        );
    }
}
