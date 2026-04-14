<?php

declare(strict_types=1);

use App\Domain\Job\JobStatus;
use App\Infrastructure\Db\PdoFactory;
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

function requestJsonWithHeaders(string $method, string $path, array $payload, array $headers): array
{
    $url = 'http://127.0.0.1:8080' . $path;
    $ch = curl_init($url);
    if ($ch === false) {
        throw new TestFailed('Failed to initialize cURL.');
    }

    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded)) {
        throw new TestFailed('Failed to encode payload.');
    }

    $httpHeaders = ['Content-Type: application/json'];
    foreach ($headers as $key => $value) {
        $httpHeaders[] = sprintf('%s: %s', $key, $value);
    }

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $httpHeaders,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => $encoded,
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new TestFailed('HTTP request failed: ' . $error);
    }

    $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        throw new TestFailed(sprintf('Response is not valid JSON. path=%s body=%s', $path, $response));
    }

    return ['status' => $statusCode, 'json' => $decoded];
}

function resetVideoJobs(PDO $pdo): void
{
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    $pdo->exec('TRUNCATE TABLE video_jobs');
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

function insertSeedJob(PDO $pdo, string $jobId, string $status, ?array $assets = null): void
{
    $settings = ['retention_days' => 7, 'enable_subtitles' => true, 'extraction_count' => 3];
    $settingsJson = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $assetsJson = $assets === null ? null : json_encode($assets, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

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
        ':file_hash' => hash('sha256', 'issue4-' . $jobId),
        ':settings_hash' => hash('sha256', (string) $settingsJson),
        ':original_file_name' => 'seed.mp4',
        ':status' => $status,
        ':settings' => $settingsJson,
        ':assets' => $assetsJson,
        ':completed_at' => $status === JobStatus::COMPLETED ? gmdate('Y-m-d H:i:s') : null,
    ]);
}

function fetchJob(PDO $pdo, string $jobId): array
{
    $stmt = $pdo->prepare('SELECT status, assets FROM video_jobs WHERE job_id = :job_id');
    $stmt->execute([':job_id' => $jobId]);
    $row = $stmt->fetch();
    if ($row === false) {
        throw new TestFailed('Seed job not found: ' . $jobId);
    }

    return [
        'status' => (string) $row['status'],
        'assets' => $row['assets'] === null ? null : json_decode((string) $row['assets'], true),
    ];
}

function signWebhookPayload(string $secret, int $timestamp, array $payload): array
{
    $raw = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($raw)) {
        throw new TestFailed('Failed to encode webhook payload.');
    }
    $signature = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $raw, $secret);

    return [
        'body' => $payload,
        'headers' => [
            'X-Webhook-Timestamp' => (string) $timestamp,
            'X-Hub-Signature-256' => $signature,
        ],
    ];
}

function testReplayAttackBlocked(PDO $pdo, string $secret): void
{
    resetVideoJobs($pdo);
    $jobId = Uuid::v4();
    insertSeedJob($pdo, $jobId, JobStatus::ANALYZING, ['original_video_id' => 'origin-a']);

    $payload = [
        'job_id' => $jobId,
        'status' => JobStatus::EDITING,
        'assets' => ['text_asset_id' => 'text-a'],
    ];
    $signed = signWebhookPayload($secret, time() - 600, $payload);
    $response = requestJsonWithHeaders('POST', '/api/webhooks/github', $signed['body'], $signed['headers']);

    assertTrue($response['status'] === 401, 'Expired webhook must be rejected with HTTP 401.');
    $job = fetchJob($pdo, $jobId);
    assertTrue($job['status'] === JobStatus::ANALYZING, 'Expired webhook must not update job status.');
}

function testReverseTransitionGuard(PDO $pdo, string $secret): void
{
    resetVideoJobs($pdo);
    $jobId = Uuid::v4();
    insertSeedJob($pdo, $jobId, JobStatus::COMPLETED, [
        'original_video_id' => 'orig-b',
        'text_asset_id' => 'text-b',
        'completed_shorts' => ['short-1'],
    ]);

    $payload = [
        'job_id' => $jobId,
        'status' => JobStatus::ANALYZING,
        'assets' => ['completed_shorts' => ['short-late']],
    ];
    $signed = signWebhookPayload($secret, time(), $payload);
    $response = requestJsonWithHeaders('POST', '/api/webhooks/github', $signed['body'], $signed['headers']);

    assertTrue($response['status'] === 200, 'Reverse transition must return HTTP 200 with ignore semantics.');
    assertTrue(($response['json']['status'] ?? null) === 'ignored', 'Reverse transition must be ignored.');

    $job = fetchJob($pdo, $jobId);
    assertTrue($job['status'] === JobStatus::COMPLETED, 'Completed job must not regress to analyzing.');
    assertTrue(($job['assets']['completed_shorts'] ?? []) === ['short-1'], 'Ignored webhook must not overwrite assets.');
}

function testAssetsPartialMerge(PDO $pdo, string $secret): void
{
    resetVideoJobs($pdo);
    $jobId = Uuid::v4();
    insertSeedJob($pdo, $jobId, JobStatus::ANALYZING, ['original_video_id' => 'orig-c']);

    $payload = [
        'job_id' => $jobId,
        'status' => JobStatus::EDITING,
        'assets' => ['text_asset_id' => 'text-c'],
    ];
    $signed = signWebhookPayload($secret, time(), $payload);
    $response = requestJsonWithHeaders('POST', '/api/webhooks/github', $signed['body'], $signed['headers']);
    assertTrue($response['status'] === 200, 'Valid webhook must return HTTP 200. got=' . json_encode($response));

    $job = fetchJob($pdo, $jobId);
    assertTrue($job['status'] === JobStatus::EDITING, 'Webhook should advance status.');
    assertTrue(($job['assets']['original_video_id'] ?? null) === 'orig-c', 'assets merge must keep existing keys.');
    assertTrue(($job['assets']['text_asset_id'] ?? null) === 'text-c', 'assets merge must append new keys.');
}

try {
    $secret = getenv('WEBHOOK_SECRET');
    if (!is_string($secret) || trim($secret) === '') {
        throw new TestFailed('WEBHOOK_SECRET must be configured.');
    }

    $pdo = PdoFactory::createFromEnv();
    $migration = file_get_contents(dirname(__DIR__) . '/src/Infrastructure/Db/Migrations/001_create_video_jobs.sql');
    if ($migration === false) {
        throw new TestFailed('Failed to load migration SQL.');
    }
    $pdo->exec($migration);

    testReplayAttackBlocked($pdo, $secret);
    testReverseTransitionGuard($pdo, $secret);
    testAssetsPartialMerge($pdo, $secret);

    echo "OK: Issue #4 quality tests passed." . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, "FAILED: {$e->getMessage()}" . PHP_EOL);
    exit(1);
}
