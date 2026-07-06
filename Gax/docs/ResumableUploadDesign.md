# Cloud SDK for Resumable Uploads: PHP Implementation Design (GAX & GAPIC Generator)

**Status:** Current / Approved Design  
**Authors:** Antigravity & User  
**Target Libraries:** `google-cloud-php/Gax` (`Google\ApiCore`) & `gapic-generator-php`

---

## 1. Executive Summary

Large, resilient HTTP/HTTPS file transfers in Google Cloud and Google Ads are powered by an internal shared service (historically referred to as "Scotty"). To provide a clean, idiomatic developer experience, the public PHP SDK encapsulates this protocol under the **Resumable Upload** API (`Google\ApiCore\ResumableUpload`).

Based on the **Universal Resumable Upload Protocol Specification**, this document defines the complete architectural design for implementing Resumable Upload support in the **PHP Cloud SDK ecosystem**.

The implementation requires synchronized changes across two core libraries:
1. **GAX (`Google\ApiCore`)**: Introducing a robust runtime state machine (`ResumableUploadSession`, `ResumableUploadStateMachine`) and user-facing uploader (`ResumableUploader`). Network requests are executed directly through the generated GAPIC client via `GapicClientTrait` (supporting both new uploads and `resumeUpload`).
2. **GAPIC Generator (`gapic-generator-php`)**: Updating AST generation in `GapicClientV2Generator` to emit factory methods returning `ResumableUploader` instances for opted-in resumable upload RPCs.

---

## 2. End-User Data & Interaction Flow

Uploading via Resumable Uploads involves four distinct categories of user-supplied data:

1. **Data Object**: The raw binary payload to upload, represented as a PSR-7 `StreamInterface` or PHP stream resource (`$dataStream`).
2. **Metadata**:
   - **Semantic Metadata**: The domain protobuf request message (e.g., `CreateYouTubeVideoUploadRequest`) serialized to JSON as the body of the initial `start` command.
   - **Operational Metadata**: Content-Type, total payload size (optional).
3. **Request Metadata**: Initial HTTP headers sent *only* during session initiation (e.g., auth credentials, routing headers, developer tokens).
4. **Upload Options**:
   - Deadline & `RetrySettings`
   - Client chunk size preference (default: 8MB)
   - Progress notification callback (`callable $progressCallback`)
   - PSR-3 Logger (`Psr\Log\LoggerInterface`)

### User Interaction Model

Using `YouTubeVideoUploadServiceClient::createYouTubeVideoUpload` as the canonical example:

```php
use Google\Cloud\YouTube\V1\Client\YouTubeVideoUploadServiceClient;
use Google\Cloud\YouTube\V1\CreateYouTubeVideoUploadRequest;

$client = new YouTubeVideoUploadServiceClient();
$request = new CreateYouTubeVideoUploadRequest([
    'title' => 'My Awesome Video',
    'description' => 'Uploaded via Resumable Upload Protocol'
]);

// 1. End-user calls generated client method and receives an initialized ResumableUploader
$uploader = $client->createYouTubeVideoUpload($request, [
    'chunkSize' => 8 * 1024 * 1024, // 8MB
    'progressCallback' => function (int $bytesUploaded) {
        echo "Successfully committed $bytesUploaded bytes\n";
    }
]);

// 2. End-user initiates upload by passing the video data stream
$stream = GuzzleHttp\Psr7\Utils::streamFor(fopen('/path/to/video.mp4', 'r'));
$result = $uploader->startUpload($stream);
```

---

## 3. GAX (`Google\ApiCore\ResumableUpload`) Runtime Architecture

To maximize testability, reuse existing middleware, and maintain separation of concerns, the runtime protocol implementation coordinates between `ResumableUploader` (user-facing I/O loop), the GAPIC client (`GapicClientTrait`), and stateless state tracking:

```mermaid
graph TD
    User[End-User Code] -->|startUpload| Uploader[ResumableUploader<br/>I/O Loop]
    Uploader -->|sendRequest| Transport[RestTransport<br/>HTTP Execution]
    Uploader -->|Pumps Events| Session[ResumableUploadSession<br/>State Tracking]
    Session -->|Passes State & Events| StateMachine[ResumableUploadStateMachine<br/>Stateless Pure Logic]
    StateMachine -->|Returns Instructions| Session
    Session -->|Returns Instructions| Uploader
    Transport -->|HTTP POST/PUT| UploadServer[Google Resumable Endpoint]
```

### A. The Uploader (`ResumableUploader`)
`ResumableUploader` manages the stream reading, auth headers (`CredentialsWrapper`), and event loop, while delegating network execution to `TransportInterface::sendRequest()`.
- **Responsibilities**:
  - Entry point `startUpload(StreamInterface $stream)`.
  - Executes HTTP requests through the client's configured `RestTransport` (or automatically instantiates a `RestTransport` fallback inline if the client was configured for gRPC).
  - Supports resuming existing sessions via `$client->resumeUpload($uploadUrl)`.

### B. The Session (`Google\ApiCore\ResumableUpload\ResumableUploadSession`)
Maintains mutable session state and buffers.
- **Responsibilities**:
  - Tracks current state machine phase (`INITIALIZING`, `STARTING`, `TRANSMITTING`, `FINALIZING`, `RECOVERY`, `CANCELLING`, `DONE`, `ERROR`, `REJECTED`).
  - Maintains upload progress metrics (`$bytesUploaded`, `$committedOffset`, `$uploadUrl`).
  - Maps raw Client I/O responses into structured `ResumableUploadEvent` DTOs.

### C. The State Machine (`Google\ApiCore\ResumableUpload\ResumableUploadStateMachine`)
A 100% stateless, deterministic class implementing the protocol transition tables.
- **Signature**: `public static function decide(ResumableUploadState $state, ResumableUploadEvent $event): ResumableUploadInstruction`

---

## 4. Protocol Phases & State Machine Specification

### 4.1 Deadline Management
- **Global Deadline**: Precedence order: `User Option` > `gRPC Service Config` > `Default (10 minutes)`.
- **Optional Upward Revision**: If using the default deadline and total upload size is known, the library calculates `Size / 100 Mb/s`. If this duration exceeds 10 minutes, the global deadline is revised upward.
- **Local Deadline**: Capped at `0.5 * Initial Global Timeout` (e.g., 5 minutes max per HTTP roundtrip).

### 4.2 Error Categorization
| Category | Description | Example HTTP Codes | Action |
| :--- | :--- | :--- | :--- |
| **Category 1** | Retriable Transient | `429`, `500`, `502`, `503`, `504`, Socket Timeout | Retry request with exponential backoff |
| **Category 2** | Recoverable State Mismatch | `400`, `412`, `416 Range Not Satisfiable` | Transition to `RECOVERY` phase (`query`) |
| **Category 3** | Fatal Unrecoverable | `401`, `403`, `404` | Bubble up `ApiException` to end-user |

### 4.3 Phase Flow Details

#### 1. `STARTING` Phase (`start` command)
- **Endpoint**: Constructed from proto `google.api.http` annotation prefixed with the service's configured upload prefix (typically `/resumable/upload`).  
  *Example:* `https://{host}/resumable/upload/v1/youTubeVideoUploads:create`
- **Headers**: Includes user auth and request headers (`X-Goog-Upload-Command: start`).
- **Body**: JSON-serialized `CreateYouTubeVideoUploadRequest`.
- **Response Handling**: Extracts `X-Goog-Upload-Status: active`, `X-Goog-Upload-Chunk-Granularity` (e.g., `50`), and `X-Goog-Upload-URL`.

#### 2. `TRANSMITTING` Phase (`upload` command)
- **Granularity Alignment**: Client adjusts its requested chunk size downwards to the closest multiple of `X-Goog-Upload-Chunk-Granularity`.
- **Headers**: `X-Goog-Upload-Command: upload`, `X-Goog-Upload-Offset: {offset}`.

#### 3. `FINALIZING` Phase (`upload, finalize` combined)
- On reaching stream EOF, if uncommitted data remains in buffer, emit `X-Goog-Upload-Command: upload, finalize`.
- Combining commands saves 1 network roundtrip and bypasses server granularity rejection rules for final non-aligned chunks.
- Expects HTTP 200 with `X-Goog-Upload-Status: final`.

#### 4. `RECOVERY` Phase (`query` command)
- Triggered on Category 2 errors. Emits `X-Goog-Upload-Command: query`.
- **Infinite Loop Guard**: The library tracks recovery attempts. If `query` returns the *exact same committed offset* more than 3 consecutive times, the session terminates with a fatal `ApiException`.

---

## 5. GAPIC Generator (`gapic-generator-php`) Modifications

To generate Resumable Upload client libraries automatically, the generator is enhanced across AST pipeline stages:

### 5.1 Service & Method Classification (`ServiceDetails` / `MethodDetails`)
1. **Config Ingestion**: Read `upload_prefix` from `service.yaml` publishing settings or `gapic.yaml` (e.g., `/resumable/upload`).
2. **Method Identification**: Add `MethodDetails::RESUMABLE_UPLOAD = 'resumable_upload'`. Detect methods opted into resumable uploads via proto annotations or service publishing configuration.

### 5.2 Client Code Emission (`GapicClientV2Generator`)
For standard RPCs, the generator emits `$this->startCall(...)`. For `RESUMABLE_UPLOAD` RPCs (like `createYouTubeVideoUpload`), `GapicClientV2Generator` emits a factory method returning `ResumableUploader` (automatically building a `RestTransport` fallback if the client is using gRPC):

```php
public function createYouTubeVideoUpload(CreateYouTubeVideoUploadRequest $request, array $optionalArgs = []): ResumableUploader
{
    $requestParams = new RequestParamsHeaderDescriptor([...]);
    $optionalArgs += [
        'headers' => [],
    ];
    $transport = $this->transport instanceof RestTransport || $this->transport instanceof GrpcFallbackTransport
        ? $this->transport
        : $this->createTransport('youtube.googleapis.com', 'rest', []);

    return new ResumableUploader(
        $transport,
        $this->credentialsWrapper,
        $this->agentHeader,
        'youtube.googleapis.com',
        '/resumable/upload', // configured upload prefix
        'v1/youTubeVideoUploads:create',
        $request,
        $optionalArgs['headers'] ?? [],
        $optionalArgs['retrySettings'] ?? null
    );
}
```
