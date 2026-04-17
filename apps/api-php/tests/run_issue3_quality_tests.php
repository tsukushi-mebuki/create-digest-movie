<?php

declare(strict_types=1);

use App\Domain\Job\JobStatus;
use App\Infrastructure\Db\PdoFactory;
use App\Infrastructure\Repository\JobRepository;
use App\Support\Uuid;

require dirname(__DIR__) . '/bootstrap.php';

final class TestFailed extends RuntimeException
{
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new TestFailed($message);
    }
}

function requestJson(string $method, string $path, ?array $payload = null, array $headers = []): array
{
    $url = 'http://127.0.0.1:8080' . $path;
    $ch = curl_init($url);
    if ($ch === false) {
        throw new TestFailed('Failed to initialize cURL.');
    }

    $requestHeaders = array_merge(['Content-Type: application/json'], $headers);
    $options = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $requestHeaders,
        CURLOPT_RETURNTRANSFER => true,
    ];

    if ($payload !== null) {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new TestFailed('Failed to encode payload.');
        }
        $options[CURLOPT_POSTFIELDS] = $encoded;
    }

    curl_setopt_array($ch, $options);
    $responseBody = curl_exec($ch);
    if ($responseBody === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new TestFailed('HTTP request failed: ' . $error);
    }

    $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $decoded = json_decode($responseBody, true);
    if (!is_array($decoded)) {
        throw new TestFailed(sprintf('Response is not valid JSON. path=%s body=%s', $path, $responseBody));
    }

    return ['status' => $statusCode, 'json' => $decoded];
}

function requestGithubWebhook(array $payload, int $timestamp): array
{
    $secret = getenv('WEBHOOK_SECRET');
    if ($secret === false || trim($secret) === '') {
        throw new TestFailed('WEBHOOK_SECRET is required for webhook tests.');
    }

    $raw = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($raw)) {
        throw new TestFailed('Failed to encode webhook payload.');
    }

    $signature = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $raw, $secret);

    return requestJson('POST', '/api/webhooks/github', $payload, [
        'X-Webhook-Timestamp: ' . $timestamp,
        'X-Hub-Signature-256: ' . $signature,
    ]);
}

function resetVideoJobs(PDO $pdo): void
{
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    $pdo->exec('TRUNCATE TABLE video_jobs');
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

function insertSeedJob(
    PDO $pdo,
    string $jobId,
    string $status,
    ?array $assets = null,
    ?string $completedAt = null
): void {
    $sql = <<<'SQL'
        INSERT INTO video_jobs (
            job_id,
            source_job_id,
            file_hash,
            settings_hash,
            original_file_name,
            status,
            asset_integrity,
            settings,
            assets,
            completed_at
        ) VALUES (
            :job_id,
            NULL,
            :file_hash,
            :settings_hash,
            :original_file_name,
            :status,
            'valid',
            :settings,
            :assets,
            :completed_at
        )
    SQL;

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':job_id' => $jobId,
        ':file_hash' => hash('sha256', 'seed-' . $jobId),
        ':settings_hash' => hash('sha256', '{"retention_days":7}'),
        ':original_file_name' => 'seed.mp4',
        ':status' => $status,
        ':settings' => json_encode(['retention_days' => 7], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':assets' => $assets === null ? null : json_encode($assets, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':completed_at' => $completedAt,
    ]);
}

function fetchJob(PDO $pdo, string $jobId): array
{
    $stmt = $pdo->prepare('SELECT status, assets, completed_at FROM video_jobs WHERE job_id = :job_id');
    $stmt->execute([':job_id' => $jobId]);
    $row = $stmt->fetch();
    if ($row === false) {
        throw new TestFailed('Job not found in DB. job_id=' . $jobId);
    }

    return $row;
}

function testDispatchIdempotency(JobRepository $repository, PDO $pdo): void
{
    resetVideoJobs($pdo);
    $jobId = Uuid::v4();
    insertSeedJob($pdo, $jobId, JobStatus::PENDING);

    $dispatchCount = 0;
    $first = $repository->dispatchIfAllowed($jobId, static function () use (&$dispatchCount): void {
        $dispatchCount++;
    });
    assertTrue($first === 'dispatched', 'First dispatch must be dispatched.');
    assertTrue($dispatchCount === 1, 'Dispatch callback must run once for first attempt.');

    $second = $repository->dispatchIfAllowed($jobId, static function () use (&$dispatchCount): void {
        $dispatchCount++;
    });
    assertTrue($second === 'skipped', 'Second dispatch must be skipped by status guard.');
    assertTrue($dispatchCount === 1, 'Dispatch callback must not run on skipped dispatch.');

    $row = fetchJob($pdo, $jobId);
    assertTrue($row['status'] === JobStatus::ANALYZING, 'Status must be analyzing after first dispatch.');
}

function testWebhookReplayBlocked(PDO $pdo): void
{
    resetVideoJobs($pdo);
    $jobId = Uuid::v4();
    insertSeedJob($pdo, $jobId, JobStatus::ANALYZING);

    $payload = [
        'job_id' => $jobId,
        'status' => JobStatus::EDITING,
        'assets' => ['text_asset_id' => 'text-asset-1'],
    ];

    $response = requestGithubWebhook($payload, time() - 600);
    assertTrue($response['status'] === 401, 'Replay request older than 5 minutes must be rejected with 401.');

    $row = fetchJob($pdo, $jobId);
    assertTrue($row['status'] === JobStatus::ANALYZING, 'Replay rejection must not update status.');
    assertTrue($row['assets'] === null, 'Replay rejection must not update assets.');
}

function testWebhookReverseTransitionIgnored(PDO $pdo): void
{
    resetVideoJobs($pdo);
    $jobId = Uuid::v4();
    insertSeedJob(
        $pdo,
        $jobId,
        JobStatus::COMPLETED,
        ['original_video_id' => 'orig-a'],
        gmdate('Y-m-d H:i:s', time() - 120)
    );

    $payload = [
        'job_id' => $jobId,
        'status' => JobStatus::ANALYZING,
        'assets' => ['text_asset_id' => 'late-text'],
    ];

    $response = requestGithubWebhook($payload, time());
    assertTrue($response['status'] === 200, 'Reverse transition should return 200 (ignored).');
    assertTrue(($response['json']['status'] ?? null) === 'ignored', 'Reverse transition response must be ignored.');

    $row = fetchJob($pdo, $jobId);
    assertTrue($row['status'] === JobStatus::COMPLETED, 'Reverse transition must not overwrite completed status.');
    $assets = $row['assets'] === null ? null : json_decode((string) $row['assets'], true);
    assertTrue(($assets['original_video_id'] ?? null) === 'orig-a', 'Existing assets must remain intact when reverse is ignored.');
    assertTrue(!isset($assets['text_asset_id']), 'Ignored reverse update must not merge incoming assets.');
}

function testWebhookPartialMerge(PDO $pdo): void
{
    resetVideoJobs($pdo);
    $jobId = Uuid::v4();
    insertSeedJob(
        $pdo,
        $jobId,
        JobStatus::EDITING,
        ['original_video_id' => 'orig-1', 'completed_shorts' => ['short-old']]
    );

    $payload = [
        'job_id' => $jobId,
        'status' => JobStatus::COMPLETED,
        'assets' => [
            'text_asset_id' => 'text-1',
            'completed_shorts' => ['short-new'],
        ],
    ];

    $response = requestGithubWebhook($payload, time());
    assertTrue($response['status'] === 200, 'Valid webhook update must return 200.');
    assertTrue(($response['json']['status'] ?? null) === 'ok', 'Valid webhook update must return ok.');

    $row = fetchJob($pdo, $jobId);
    assertTrue($row['status'] === JobStatus::COMPLETED, 'Status must be updated to completed.');
    assertTrue($row['completed_at'] !== null, 'completed_at must be populated on completed status.');
    $assets = $row['assets'] === null ? null : json_decode((string) $row['assets'], true);
    assertTrue(is_array($assets), 'Assets must be persisted as JSON object.');
    assertTrue(($assets['original_video_id'] ?? null) === 'orig-1', 'Partial merge must keep existing original_video_id.');
    assertTrue(($assets['text_asset_id'] ?? null) === 'text-1', 'Partial merge must include new text_asset_id.');
    assertTrue(($assets['completed_shorts'][0] ?? null) === 'short-new', 'Incoming completed_shorts should override only that key.');
}

try {
    $pdo = PdoFactory::createFromEnv();
    $migration = file_get_contents(dirname(__DIR__) . '/src/Infrastructure/Db/Migrations/001_create_video_jobs.sql');
    if ($migration === false) {
        throw new TestFailed('Failed to load migration SQL.');
    }
    $pdo->exec($migration);
    $repository = new JobRepository($pdo);

    testDispatchIdempotency($repository, $pdo);
    testWebhookReplayBlocked($pdo);
    testWebhookReverseTransitionIgnored($pdo);
    testWebhookPartialMerge($pdo);

    echo "OK: Issue #3 quality tests passed." . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, "FAILED: {$e->getMessage()}" . PHP_EOL);
    exit(1);
}
