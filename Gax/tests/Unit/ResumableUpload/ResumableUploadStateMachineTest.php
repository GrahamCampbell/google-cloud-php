<?php
/*
 * Copyright 2026 Google LLC
 * All rights reserved.
 */

namespace Google\ApiCore\Tests\Unit\ResumableUpload;

use Google\ApiCore\ApiException;
use Google\ApiCore\ResumableUpload\ResumableUploadEvent;
use Google\ApiCore\ResumableUpload\ResumableUploadInstruction;
use Google\ApiCore\ResumableUpload\ResumableUploadStateMachine;
use Google\ApiCore\ResumableUpload\ResumableUploadState;
use PHPUnit\Framework\TestCase;

class ResumableUploadStateMachineTest extends TestCase
{
    public function testInitializingToStarting()
    {
        $state = new ResumableUploadState(phase: ResumableUploadState::INITIALIZING);
        $event = new ResumableUploadEvent(type: ResumableUploadEvent::START_UPLOAD);

        $instruction = ResumableUploadStateMachine::decide($state, $event);

        $this->assertEquals(ResumableUploadInstruction::SEND_START, $instruction->action);
        $this->assertEquals(ResumableUploadState::STARTING, $instruction->nextState);
        $this->assertEquals('start', $instruction->commandHeader);
    }

    public function testStartingToTransmitting()
    {
        $state = new ResumableUploadState(phase: ResumableUploadState::STARTING);
        $event = new ResumableUploadEvent(
            type: ResumableUploadEvent::HTTP_RESPONSE,
            httpStatusCode: 200,
            headers: ['X-Goog-Upload-Status' => 'active']
        );

        $instruction = ResumableUploadStateMachine::decide($state, $event);

        $this->assertEquals(ResumableUploadInstruction::READ_STREAM, $instruction->action);
        $this->assertEquals(ResumableUploadState::TRANSMITTING, $instruction->nextState);
    }

    public function testTransmittingChunkRead()
    {
        $state = new ResumableUploadState(phase: ResumableUploadState::TRANSMITTING, committedOffset: 100);
        $event = new ResumableUploadEvent(type: ResumableUploadEvent::CHUNK_READ, bytesRead: 50);

        $instruction = ResumableUploadStateMachine::decide($state, $event);

        $this->assertEquals(ResumableUploadInstruction::SEND_UPLOAD, $instruction->action);
        $this->assertEquals(ResumableUploadState::TRANSMITTING, $instruction->nextState);
        $this->assertEquals('upload', $instruction->commandHeader);
        $this->assertEquals(100, $instruction->offsetHeader);
    }

    public function testTransmittingEofReachedWithData()
    {
        $state = new ResumableUploadState(phase: ResumableUploadState::TRANSMITTING, committedOffset: 200);
        $event = new ResumableUploadEvent(type: ResumableUploadEvent::EOF_REACHED, bytesRead: 30);

        $instruction = ResumableUploadStateMachine::decide($state, $event);

        $this->assertEquals(ResumableUploadInstruction::SEND_UPLOAD_FINALIZE, $instruction->action);
        $this->assertEquals(ResumableUploadState::FINALIZING, $instruction->nextState);
        $this->assertEquals('upload, finalize', $instruction->commandHeader);
    }

    public function testRecoverableErrorTriggersQuery()
    {
        $state = new ResumableUploadState(phase: ResumableUploadState::TRANSMITTING);
        $event = new ResumableUploadEvent(type: ResumableUploadEvent::ERROR_RECOVERABLE);

        $instruction = ResumableUploadStateMachine::decide($state, $event);

        $this->assertEquals(ResumableUploadInstruction::SEND_QUERY, $instruction->action);
        $this->assertEquals(ResumableUploadState::RECOVERY, $instruction->nextState);
        $this->assertEquals('query', $instruction->commandHeader);
    }

    public function testFinalizingSuccess()
    {
        $state = new ResumableUploadState(phase: ResumableUploadState::FINALIZING);
        $event = new ResumableUploadEvent(
            type: ResumableUploadEvent::HTTP_RESPONSE,
            httpStatusCode: 200,
            headers: ['X-Goog-Upload-Status' => 'final']
        );

        $instruction = ResumableUploadStateMachine::decide($state, $event);

        $this->assertEquals(ResumableUploadInstruction::TERMINATE_SUCCESS, $instruction->action);
        $this->assertEquals(ResumableUploadState::DONE, $instruction->nextState);
    }

    public function testRejectionFlow()
    {
        $state = new ResumableUploadState(phase: ResumableUploadState::TRANSMITTING);
        $event = new ResumableUploadEvent(
            type: ResumableUploadEvent::HTTP_RESPONSE,
            httpStatusCode: 400,
            headers: ['X-Goog-Upload-Status' => 'final'],
            body: 'Invalid video format'
        );

        $instruction = ResumableUploadStateMachine::decide($state, $event);

        $this->assertEquals(ResumableUploadInstruction::TERMINATE_REJECTED, $instruction->action);
        $this->assertEquals(ResumableUploadState::REJECTED, $instruction->nextState);
        $this->assertInstanceOf(ApiException::class, $instruction->exception);
    }

    public function testResumeUploadTriggersQuery()
    {
        $state = new ResumableUploadState(phase: ResumableUploadState::RECOVERY, uploadUrl: 'https://example.com/upload');
        $event = new ResumableUploadEvent(type: ResumableUploadEvent::START_UPLOAD);

        $instruction = ResumableUploadStateMachine::decide($state, $event);

        $this->assertEquals(ResumableUploadInstruction::SEND_QUERY, $instruction->action);
        $this->assertEquals(ResumableUploadState::RECOVERY, $instruction->nextState);
        $this->assertEquals('query', $instruction->commandHeader);
    }

    public function testTransientErrorRepeatsPhase()
    {
        $state = new ResumableUploadState(phase: ResumableUploadState::TRANSMITTING, committedOffset: 500);
        $event = new ResumableUploadEvent(type: ResumableUploadEvent::ERROR_TRANSIENT);

        $instruction = ResumableUploadStateMachine::decide($state, $event);

        $this->assertEquals(ResumableUploadInstruction::SEND_UPLOAD, $instruction->action);
        $this->assertEquals(ResumableUploadState::TRANSMITTING, $instruction->nextState);
        $this->assertEquals('upload', $instruction->commandHeader);
        $this->assertEquals(500, $instruction->offsetHeader);
    }
}

