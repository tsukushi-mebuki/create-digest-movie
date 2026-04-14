<?php

declare(strict_types=1);

use App\Domain\Job\JobStatus;
use App\Infrastructure\Repository\JobRepository;

require dirname(__DIR__) . '/bootstrap.php';

header('Content-Type: application/json');

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
    $repository = JobRepository::fromEnv();

    if ($method === 'POST' && $path === '/api/jobs/init') {
        handleInit($repository);
        return;
    }

    if ($method === 'GET' && preg_match('#^/api/jobs/([0-9a-fA-F-]{36})$#', $path, $matches) === 1) {
        handleGetJob($repository, strtolower($matches[1]));
        return;
    }

    if ($method === 'POST' && $path === '/api/jobs/dispatch') {
        handleDispatch($repository);
        return;
    }

    if ($method === 'POST' && $path === '/api/webhooks/github') {
        handleGithubWebhook($repository);
        return;
    }

    respondJson(404, ['error' => 'Not found']);
} catch (\Throwable $e) {
    respondJson(500, ['error' => 'Internal server error', 'detail' => $e->getMessage()]);
}

function handleInit(JobRepository $repository): void
{
    $payload = readJsonBody();
    $fileHash = requireString($payload, 'file_hash');
    $originalFileName = requireString($payload, 'original_file_name');
    $settings = requireArray($payload, 'settings');

    // Why: We never trust client-provided settings_hash for dedupe decisions.
    $normalizedSettingsHash = normalizedSettingsHash($settings);

    $exact = $repository->findExactCompletedDuplicate($fileHash, $normalizedSettingsHash);
    if ($exact !== null) {
        $jobId = $repository->createDerivedJob(
            $fileHash,
            $normalizedSettingsHash,
            $originalFileName,
            $settings,
            (string) $exact['job_id'],
            JobStatus::COMPLETED,
            gmdate('Y-m-d H:i:s')
        );
        respondJson(200, ['job_id' => $jobId, 'upload_url' => null, 'status' => JobStatus::COMPLETED]);
        return;
    }

    $partial = $repository->findPartialDuplicateSource($fileHash, $normalizedSettingsHash);
    if ($partial !== null) {
        $jobId = $repository->createDerivedJob(
            $fileHash,
            $normalizedSettingsHash,
            $originalFileName,
            $settings,
            (string) $partial['job_id'],
            JobStatus::PENDING,
            null
        );
        respondJson(200, ['job_id' => $jobId, 'upload_url' => null, 'status' => JobStatus::PENDING]);
        return;
    }

    $jobId = $repository->createPendingJob($fileHash, $normalizedSettingsHash, $originalFileName, $settings);
    $uploadUrl = createDriveResumableUploadUrl($jobId, $originalFileName);
    respondJson(200, ['job_id' => $jobId, 'upload_url' => $uploadUrl, 'status' => JobStatus::PENDING]);
}

function handleGetJob(JobRepository $repository, string $jobId): void
{
    $job = $repository->findJobWithResolvedAssets($jobId);
    if ($job === null) {
        respondJson(404, ['error' => 'Job not found']);
        return;
    }

    respondJson(200, $job);
}

/**
 * Why: dispatch must be idempotent by job status to avoid duplicate pipeline starts.
 */
function handleDispatch(JobRepository $repository): void
{
    $payload = readJsonBody();
    $jobId = requireString($payload, 'job_id');

    $outcome = $repository->dispatchIfAllowed($jobId, static function (string $targetJobId): void {
        dispatchGithubWorkflow($targetJobId);
    });

    if ($outcome === 'not_found') {
        respondJson(404, ['error' => 'Job not found']);
        return;
    }

    if ($outcome === 'skipped') {
        respondJson(200, ['status' => 'skipped']);
        return;
    }

    respondJson(200, ['status' => 'accepted']);
}

/**
 * Why: Webhook endpoint enforces signature + timestamp window against replay attacks.
 */
function handleGithubWebhook(JobRepository $repository): void
{
    $rawBody = readRawBody();
    verifyWebhookSignatureAndTimestamp($rawBody);

    $payload = decodeJsonBody($rawBody);
    $jobId = requireString($payload, 'job_id');
    $status = requireJobStatus($payload, 'status');
    $assets = optionalAssocArray($payload, 'assets');
    $completedAt = optionalString($payload, 'completed_at');

    $outcome = $repository->applyWebhookUpdate($jobId, $status, $assets, $completedAt);
    if ($outcome === 'not_found') {
        respondJson(404, ['error' => 'Job not found']);
        return;
    }

    if ($outcome === 'skipped') {
        respondJson(200, ['status' => 'ignored']);
        return;
    }

    if ($status === JobStatus::EDITING) {
        // Why: Once transcript processing marks editing, we must chain clip generation automatically.
        dispatchGithubWorkflowIfConfigured($jobId, 'GITHUB_CLIPS_WORKFLOW_FILE', 'pipeline-clips.yml');
    }

    respondJson(200, ['status' => 'ok']);
}

function dispatchGithubWorkflow(string $jobId): void
{
    dispatchGithubWorkflowWithWorkflow($jobId, 'GITHUB_WORKFLOW_FILE', 'pipeline-transcribe.yml');
}

/**
 * Why: Local/test environments may omit GitHub dispatch secrets; webhook update itself must still succeed.
 */
function dispatchGithubWorkflowIfConfigured(string $jobId, string $workflowEnvKey, string $defaultWorkflow): void
{
    $token = getenv('GITHUB_TOKEN');
    $owner = getenv('GITHUB_REPO_OWNER');
    $repo = getenv('GITHUB_REPO_NAME');
    $explicitEndpoint = getenv('GITHUB_DISPATCH_ENDPOINT');
    $hasEndpoint = is_string($explicitEndpoint) && trim($explicitEndpoint) !== '';
    $hasRepoConfig = is_string($owner) && trim($owner) !== '' && is_string($repo) && trim($repo) !== '';
    $hasToken = is_string($token) && trim($token) !== '';
    if ((!$hasEndpoint && !$hasRepoConfig) || !$hasToken) {
        error_log('GitHub dispatch skipped: missing GITHUB_TOKEN or repository dispatch config.');
        return;
    }

    dispatchGithubWorkflowWithWorkflow($jobId, $workflowEnvKey, $defaultWorkflow);
}

function dispatchGithubWorkflowWithWorkflow(string $jobId, string $workflowEnvKey, string $defaultWorkflow): void
{
    $endpoint = getenv('GITHUB_DISPATCH_ENDPOINT');
    if ($endpoint === false || trim($endpoint) === '') {
        $owner = requiredEnv('GITHUB_REPO_OWNER');
        $repo = requiredEnv('GITHUB_REPO_NAME');
        $workflow = getenv($workflowEnvKey);
        if ($workflow === false || trim($workflow) === '') {
            $workflow = $defaultWorkflow;
        }
        $endpoint = sprintf(
            'https://api.github.com/repos/%s/%s/actions/workflows/%s/dispatches',
            rawurlencode($owner),
            rawurlencode($repo),
            rawurlencode($workflow)
        );
    }

    $token = requiredEnv('GITHUB_TOKEN');
    $ref = getenv('GITHUB_DISPATCH_REF');
    if ($ref === false || trim($ref) === '') {
        $ref = 'master';
    }

    $body = json_encode([
        'ref' => $ref,
        'inputs' => ['job_id' => $jobId],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($body)) {
        throw new \RuntimeException('Failed to encode GitHub dispatch payload.');
    }

    if (!function_exists('curl_init')) {
        throw new \RuntimeException('cURL extension is required for GitHub dispatch.');
    }

    $ch = curl_init($endpoint);
    if ($ch === false) {
        throw new \RuntimeException('Failed to initialize cURL for GitHub dispatch.');
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/vnd.github+json',
            'Authorization: Bearer ' . $token,
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: create-digest-movie-dispatcher',
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new \RuntimeException('GitHub dispatch request failed: ' . $error);
    }

    $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($statusCode < 200 || $statusCode >= 300) {
        throw new \RuntimeException('GitHub dispatch returned HTTP ' . $statusCode);
    }
}

/**
 * Why: Service account key stays in env secret, and short-lived access token is minted on demand.
 */
function createDriveResumableUploadUrl(string $jobId, string $originalFileName): string
{
    $accessToken = buildGoogleAccessToken();
    $endpoint = getenv('GOOGLE_DRIVE_RESUMABLE_ENDPOINT');
    if ($endpoint === false || $endpoint === '') {
        $endpoint = 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable';
    }

    $metadata = [
        'name' => $originalFileName,
        'appProperties' => ['job_id' => $jobId],
    ];

    if (!function_exists('curl_init')) {
        throw new \RuntimeException('cURL extension is required to create Drive resumable upload URL.');
    }

    $ch = curl_init($endpoint);
    if ($ch === false) {
        throw new \RuntimeException('Failed to initialize cURL for Drive upload URL creation.');
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json; charset=UTF-8',
            'X-Upload-Content-Type: video/mp4',
        ],
        CURLOPT_POSTFIELDS => json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        CURLOPT_HEADER => true,
        CURLOPT_RETURNTRANSFER => true,
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new \RuntimeException('Failed to create Drive resumable upload URL: ' . $error);
    }

    $statusCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $rawHeaders = substr($response, 0, $headerSize);
    curl_close($ch);

    if ($statusCode < 200 || $statusCode >= 300) {
        throw new \RuntimeException('Drive resumable URL creation returned HTTP ' . $statusCode);
    }

    if (preg_match('/^Location:\s*(.+)\s*$/im', $rawHeaders, $matches) !== 1) {
        throw new \RuntimeException('Drive resumable response did not include Location header.');
    }

    return trim($matches[1]);
}

function buildGoogleAccessToken(): string
{
    $rawServiceAccountKey = getenv('GCP_SERVICE_ACCOUNT_KEY');
    if ($rawServiceAccountKey !== false && trim($rawServiceAccountKey) !== '') {
        return createServiceAccountAccessToken($rawServiceAccountKey);
    }

    // Backward compatibility for local environments still using direct token injection.
    return requiredEnv('GOOGLE_OAUTH_ACCESS_TOKEN');
}

function createServiceAccountAccessToken(string $rawServiceAccountKey): string
{
    $serviceAccount = json_decode($rawServiceAccountKey, true);
    if (!is_array($serviceAccount)) {
        throw new \RuntimeException('GCP_SERVICE_ACCOUNT_KEY must be a valid JSON string.');
    }

    $clientEmail = $serviceAccount['client_email'] ?? null;
    $privateKey = $serviceAccount['private_key'] ?? null;
    if (!is_string($clientEmail) || !is_string($privateKey) || $clientEmail === '' || $privateKey === '') {
        throw new \RuntimeException('GCP_SERVICE_ACCOUNT_KEY is missing required fields.');
    }

    $issuedAt = time();
    $expiresAt = $issuedAt + 3600;
    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $claims = [
        'iss' => $clientEmail,
        'scope' => 'https://www.googleapis.com/auth/drive.file',
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $issuedAt,
        'exp' => $expiresAt,
    ];

    $encodedHeader = base64UrlEncode((string) json_encode($header, JSON_UNESCAPED_SLASHES));
    $encodedClaims = base64UrlEncode((string) json_encode($claims, JSON_UNESCAPED_SLASHES));
    $signingInput = $encodedHeader . '.' . $encodedClaims;

    $privateKeyResource = openssl_pkey_get_private($privateKey);
    if ($privateKeyResource === false) {
        throw new \RuntimeException('Failed to parse private key from GCP_SERVICE_ACCOUNT_KEY.');
    }

    $signature = '';
    $signResult = openssl_sign($signingInput, $signature, $privateKeyResource, OPENSSL_ALGO_SHA256);
    if (PHP_VERSION_ID < 80000) {
        openssl_free_key($privateKeyResource);
    }
    if ($signResult !== true) {
        throw new \RuntimeException('Failed to sign JWT for Google OAuth token exchange.');
    }

    $jwtAssertion = $signingInput . '.' . base64UrlEncode($signature);
    $postBody = http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $jwtAssertion,
    ]);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    if ($ch === false) {
        throw new \RuntimeException('Failed to initialize cURL for token exchange.');
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS => $postBody,
        CURLOPT_RETURNTRANSFER => true,
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new \RuntimeException('Google token exchange request failed: ' . $error);
    }

    $statusCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($statusCode < 200 || $statusCode >= 300) {
        throw new \RuntimeException('Google token exchange returned HTTP ' . $statusCode);
    }

    $decoded = json_decode($response, true);
    $accessToken = $decoded['access_token'] ?? null;
    if (!is_string($accessToken) || $accessToken === '') {
        throw new \RuntimeException('Google token exchange response missing access_token.');
    }

    return $accessToken;
}

function base64UrlEncode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function readJsonBody(): array
{
    $raw = readRawBody();
    return decodeJsonBody($raw);
}

function readRawBody(): string
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        throw new \RuntimeException('Request body is required.');
    }

    return $raw;
}

function decodeJsonBody(string $rawBody): array
{
    $decoded = json_decode($rawBody, true);
    if (!is_array($decoded)) {
        throw new \RuntimeException('Request body must be valid JSON object.');
    }

    return $decoded;
}

/**
 * Why: replayed requests must fail if timestamp exceeds 5 minute tolerance.
 */
function verifyWebhookSignatureAndTimestamp(string $rawBody): void
{
    $signature = getRequiredHeader('X-Hub-Signature-256', 'HTTP_X_HUB_SIGNATURE_256');
    $timestamp = getRequiredHeader('X-Webhook-Timestamp', 'HTTP_X_WEBHOOK_TIMESTAMP');
    if (!preg_match('/^\d+$/', $timestamp)) {
        respondJson(401, ['error' => 'Unauthorized']);
        exit;
    }

    $timestampInt = (int) $timestamp;
    if (abs(time() - $timestampInt) > 300) {
        respondJson(401, ['error' => 'Unauthorized']);
        exit;
    }

    $secret = getenv('GITHUB_WEBHOOK_SECRET');
    if ($secret === false || trim($secret) === '') {
        $secret = requiredEnv('WEBHOOK_SECRET');
    }

    $expected = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
    if (!hash_equals($expected, $signature)) {
        respondJson(401, ['error' => 'Unauthorized']);
        exit;
    }
}

function requireString(array $payload, string $key): string
{
    $value = $payload[$key] ?? null;
    if (!is_string($value) || trim($value) === '') {
        throw new RuntimeException(sprintf('%s is required and must be a non-empty string.', $key));
    }

    return trim($value);
}

function requireArray(array $payload, string $key): array
{
    $value = $payload[$key] ?? null;
    if (!is_array($value)) {
        throw new RuntimeException(sprintf('%s is required and must be an object.', $key));
    }

    return $value;
}

function optionalAssocArray(array $payload, string $key): ?array
{
    if (!array_key_exists($key, $payload) || $payload[$key] === null) {
        return null;
    }
    if (!is_array($payload[$key])) {
        throw new RuntimeException(sprintf('%s must be an object when provided.', $key));
    }

    return $payload[$key];
}

function optionalString(array $payload, string $key): ?string
{
    if (!array_key_exists($key, $payload) || $payload[$key] === null) {
        return null;
    }
    if (!is_string($payload[$key]) || trim($payload[$key]) === '') {
        throw new RuntimeException(sprintf('%s must be a non-empty string when provided.', $key));
    }

    return trim((string) $payload[$key]);
}

function requireJobStatus(array $payload, string $key): string
{
    $status = requireString($payload, $key);
    $allowed = [
        JobStatus::PENDING,
        JobStatus::UPLOADING,
        JobStatus::ANALYZING,
        JobStatus::EDITING,
        JobStatus::COMPLETED,
        JobStatus::FAILED,
    ];
    if (!in_array($status, $allowed, true)) {
        throw new RuntimeException(sprintf('%s has unsupported value.', $key));
    }

    return $status;
}

function getRequiredHeader(string $canonical, string $serverKey): string
{
    $value = $_SERVER[$serverKey] ?? null;
    if (!is_string($value) || trim($value) === '') {
        throw new RuntimeException(sprintf('%s header is required.', $canonical));
    }

    return trim($value);
}

function normalizedSettingsHash(array $settings): string
{
    $normalized = normalizeRecursively($settings);
    $canonicalJson = json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($canonicalJson === false) {
        throw new \RuntimeException('Failed to normalize settings JSON.');
    }

    return hash('sha256', $canonicalJson);
}

function normalizeRecursively(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }

    if (!isListArray($value)) {
        ksort($value);
    }

    foreach ($value as $key => $item) {
        $value[$key] = normalizeRecursively($item);
    }

    return $value;
}

function isListArray(array $value): bool
{
    return array_keys($value) === range(0, count($value) - 1);
}

function requiredEnv(string $key): string
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        throw new \RuntimeException('Missing required environment variable: ' . $key);
    }

    return $value;
}

function respondJson(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
