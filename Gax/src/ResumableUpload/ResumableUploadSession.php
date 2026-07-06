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

/**
 * Maintains mutable session state and coordinates Client I/O with StateMachine logic.
 */
class ResumableUploadSession
{
    private ResumableUploadState $state;

    public function __construct(?ResumableUploadState $state = null)
    {
        $this->state = $state ?? new ResumableUploadState();
    }


    public function getState(): ResumableUploadState
    {
        return $this->state;
    }

    public function processEvent(ResumableUploadEvent $event): ResumableUploadInstruction
    {
        $instruction = ResumableUploadStateMachine::decide($this->state, $event);

        $previousPhase = $this->state->phase;
        $this->state->previousPhase = $previousPhase;
        $this->state->phase = $instruction->nextState;

        if ($event->type === ResumableUploadEvent::HTTP_RESPONSE && $event->httpStatusCode === 200) {
            foreach ($event->headers as $k => $v) {
                $val = is_array($v) ? (string) reset($v) : (string) $v;
                if (strcasecmp((string) $k, 'X-Goog-Upload-URL') === 0) {
                    $this->state->uploadUrl = $val;
                }
                if (strcasecmp((string) $k, 'X-Goog-Upload-Chunk-Granularity') === 0) {
                    $this->state->chunkGranularity = (int) $val;
                }
            }
        }

        if ($instruction->action === ResumableUploadInstruction::SEND_QUERY) {
            $this->state->lastRecoveryOffset = $this->state->committedOffset;
        }

        if ($instruction->offsetHeader !== null && $this->state->phase === ResumableUploadState::TRANSMITTING) {
            $this->state->committedOffset = $instruction->offsetHeader;
        }

        if ($instruction->nextState === ResumableUploadState::RECOVERY && $instruction->offsetHeader !== null) {
            if ($instruction->offsetHeader === $this->state->lastRecoveryOffset) {
                $this->state->recoveryAttempts++;
            } else {
                $this->state->recoveryAttempts = 0;
            }
        }

        return $instruction;
    }
}
