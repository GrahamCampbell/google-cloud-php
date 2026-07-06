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

/**
 * Pure, stateless decision logic implementing the Resumable Upload state transition tables.
 */
class ResumableUploadStateMachine
{
    private static function getHeaderCaseInsensitive(array $headers, string $key): ?string
    {
        foreach ($headers as $k => $v) {
            if (strcasecmp((string) $k, $key) === 0) {
                return is_array($v) ? (string) reset($v) : (string) $v;
            }
        }
        return null;
    }

    public static function decide(ResumableUploadState $state, ResumableUploadEvent $event): ResumableUploadInstruction
    {
        if ($event->type === ResumableUploadEvent::CANCEL_REQUESTED && $state->phase !== ResumableUploadState::CANCELLING && $state->phase !== ResumableUploadState::DONE) {
            return new ResumableUploadInstruction(
                action: ResumableUploadInstruction::SEND_CANCEL,
                nextState: ResumableUploadState::CANCELLING,
                commandHeader: 'cancel'
            );
        }

        if ($event->type === ResumableUploadEvent::ERROR_FATAL) {
            return new ResumableUploadInstruction(
                action: ResumableUploadInstruction::TERMINATE_ERROR,
                nextState: ResumableUploadState::ERROR,
                exception: $event->exception ?? new ApiException('Fatal unrecoverable error during upload', 0, ApiStatus::INTERNAL)
            );
        }

        if ($event->type === ResumableUploadEvent::HTTP_RESPONSE && $event->httpStatusCode >= 400) {
            $statusHeader = self::getHeaderCaseInsensitive($event->headers, 'X-Goog-Upload-Status');
            if (strcasecmp((string) $statusHeader, 'final') === 0) {
                return new ResumableUploadInstruction(
                    action: ResumableUploadInstruction::TERMINATE_REJECTED,
                    nextState: ResumableUploadState::REJECTED,
                    exception: new ApiException($event->body ?? 'Upload rejected by server', $event->httpStatusCode, ApiStatus::INVALID_ARGUMENT)
                );
            }
        }

        if ($event->type === ResumableUploadEvent::ERROR_RECOVERABLE) {
            return new ResumableUploadInstruction(
                action: ResumableUploadInstruction::SEND_QUERY,
                nextState: ResumableUploadState::RECOVERY,
                commandHeader: 'query'
            );
        }

        if ($event->type === ResumableUploadEvent::ERROR_TRANSIENT) {
            switch ($state->phase) {
                case ResumableUploadState::STARTING:
                    return new ResumableUploadInstruction(
                        action: ResumableUploadInstruction::SEND_START,
                        nextState: ResumableUploadState::STARTING,
                        commandHeader: 'start'
                    );
                case ResumableUploadState::TRANSMITTING:
                    return new ResumableUploadInstruction(
                        action: ResumableUploadInstruction::SEND_UPLOAD,
                        nextState: ResumableUploadState::TRANSMITTING,
                        commandHeader: 'upload',
                        offsetHeader: $state->committedOffset
                    );
                case ResumableUploadState::FINALIZING:
                    return new ResumableUploadInstruction(
                        action: ResumableUploadInstruction::SEND_FINALIZE,
                        nextState: ResumableUploadState::FINALIZING,
                        commandHeader: 'finalize',
                        offsetHeader: $state->committedOffset
                    );
                case ResumableUploadState::RECOVERY:
                    return new ResumableUploadInstruction(
                        action: ResumableUploadInstruction::SEND_QUERY,
                        nextState: ResumableUploadState::RECOVERY,
                        commandHeader: 'query'
                    );
            }
        }

        switch ($state->phase) {
            case ResumableUploadState::INITIALIZING:
                if ($event->type === ResumableUploadEvent::START_UPLOAD) {
                    return new ResumableUploadInstruction(
                        action: ResumableUploadInstruction::SEND_START,
                        nextState: ResumableUploadState::STARTING,
                        commandHeader: 'start'
                    );
                }
                break;

            case ResumableUploadState::STARTING:
                if ($event->type === ResumableUploadEvent::HTTP_RESPONSE && $event->httpStatusCode === 200) {
                    return new ResumableUploadInstruction(
                        action: ResumableUploadInstruction::READ_STREAM,
                        nextState: ResumableUploadState::TRANSMITTING
                    );
                }
                break;

            case ResumableUploadState::TRANSMITTING:
                if ($event->type === ResumableUploadEvent::CHUNK_READ) {
                    return new ResumableUploadInstruction(
                        action: ResumableUploadInstruction::SEND_UPLOAD,
                        nextState: ResumableUploadState::TRANSMITTING,
                        commandHeader: 'upload',
                        offsetHeader: $state->committedOffset
                    );
                }
                if ($event->type === ResumableUploadEvent::EOF_REACHED) {
                    $action = $event->bytesRead > 0 ? ResumableUploadInstruction::SEND_UPLOAD_FINALIZE : ResumableUploadInstruction::SEND_FINALIZE;
                    $cmd = $event->bytesRead > 0 ? 'upload, finalize' : 'finalize';
                    return new ResumableUploadInstruction(
                        action: $action,
                        nextState: ResumableUploadState::FINALIZING,
                        commandHeader: $cmd,
                        offsetHeader: $state->committedOffset
                    );
                }
                if ($event->type === ResumableUploadEvent::HTTP_RESPONSE && $event->httpStatusCode === 200) {
                    return new ResumableUploadInstruction(
                        action: ResumableUploadInstruction::READ_STREAM,
                        nextState: ResumableUploadState::TRANSMITTING
                    );
                }
                break;

            case ResumableUploadState::FINALIZING:
                if ($event->type === ResumableUploadEvent::HTTP_RESPONSE && $event->httpStatusCode === 200) {
                    $statusHeader = self::getHeaderCaseInsensitive($event->headers, 'X-Goog-Upload-Status');
                    if (strcasecmp((string) $statusHeader, 'final') === 0) {
                        return new ResumableUploadInstruction(
                            action: ResumableUploadInstruction::TERMINATE_SUCCESS,
                            nextState: ResumableUploadState::DONE
                        );
                    }
                }
                break;

            case ResumableUploadState::RECOVERY:
                if ($event->type === ResumableUploadEvent::START_UPLOAD) {
                    return new ResumableUploadInstruction(
                        action: ResumableUploadInstruction::SEND_QUERY,
                        nextState: ResumableUploadState::RECOVERY,
                        commandHeader: 'query'
                    );
                }
                if ($event->type === ResumableUploadEvent::HTTP_RESPONSE && $event->httpStatusCode === 200) {
                    $serverOffsetStr = self::getHeaderCaseInsensitive($event->headers, 'X-Goog-Upload-Size-Received');
                    $serverOffset = $serverOffsetStr !== null ? (int) $serverOffsetStr : $state->committedOffset;

                    if ($serverOffset === $state->lastRecoveryOffset) {
                        if ($state->recoveryAttempts >= 3) {
                            return new ResumableUploadInstruction(
                                action: ResumableUploadInstruction::TERMINATE_ERROR,
                                nextState: ResumableUploadState::ERROR,
                                exception: new ApiException('Exhausted recovery attempts with unchanged offset', 0, ApiStatus::ABORTED)
                            );
                        }
                    }

                    $nextPhase = $state->previousPhase === ResumableUploadState::FINALIZING ? ResumableUploadState::FINALIZING : ResumableUploadState::TRANSMITTING;
                    $action = $nextPhase === ResumableUploadState::FINALIZING ? ResumableUploadInstruction::SEND_FINALIZE : ResumableUploadInstruction::READ_STREAM;
                    $cmd = $nextPhase === ResumableUploadState::FINALIZING ? 'finalize' : null;

                    return new ResumableUploadInstruction(
                        action: $action,
                        nextState: $nextPhase,
                        commandHeader: $cmd,
                        offsetHeader: $serverOffset
                    );
                }
                break;

            case ResumableUploadState::CANCELLING:
                if ($event->type === ResumableUploadEvent::HTTP_RESPONSE && $event->httpStatusCode === 200) {
                    return new ResumableUploadInstruction(
                        action: ResumableUploadInstruction::TERMINATE_ERROR,
                        nextState: ResumableUploadState::ERROR,
                        exception: new ApiException('Upload cancelled by user', 0, ApiStatus::CANCELLED)
                    );
                }
                break;
        }

        return new ResumableUploadInstruction(
            action: ResumableUploadInstruction::TERMINATE_ERROR,
            nextState: ResumableUploadState::ERROR,
            exception: new ApiException("Unexpected event {$event->type} in phase {$state->phase}", 0, ApiStatus::INTERNAL)
        );
    }
}
