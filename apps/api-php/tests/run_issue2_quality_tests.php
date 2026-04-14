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

function requestJson(string $method, string $path, ?array $payload = null): array
{
    $url = 'http://127.0.0.1:8080' . $path;
    $ch = curl_init($url);
    if ($ch === false) {
        throw new TestFailed('Failed to initialize cURL.');
    }

    $headers = ['Content-Type: application/json'];
    $options = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
    ];

    if ($payload !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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

function normalizeRecursively(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }

    if (array_keys($value) !== range(0, count($value) - 1)) {
        ksort($value);
    }

    foreach ($value as $key => $item) {
        $value[$key] = normalizeRecursively($item);
    }

    return $value;
}

function canonicalSettingsHash(array $settings): string
{
    $normalized = normalizeRecursively($settings);
    $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new TestFailed('Failed to encode normalized settings.');
    }

    return hash('sha256', $json);
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
    string $fileHash,
    string $settingsHash,
    string $status,
    string $assetIntegrity,
    array $settings,
    ?array $assets,
    ?string $completedAt
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
            :asset_integrity,
            :settings,
            :assets,
            :completed_at
        )
    SQL;

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':job_id' => $jobId,
        ':file_hash' => $fileHash,
        ':settings_hash' => $settingsHash,
        ':original_file_name' => 'seed.mp4',
        ':status' => $status,
        ':asset_integrity' => $assetIntegrity,
        ':settings' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':assets' => $assets === null ? null : json_encode($assets, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ':completed_at' => $completedAt,
    ]);
}

function testInitExactDuplicateAndTransparentGet(PDO $pdo, JobRepository $repository): void
{
    resetVideoJobs($pdo);

    $fileHash = hash('sha256', 'duplicate-video');
    $baseSettings = [
        'retention_days' => 7,
        'enable_subtitles' => true,
        'extraction_count' => 3,
        'advanced' => ['silence_threshold' => -28, 'min_length_sec' => 20],
    ];
    $settingsHash = canonicalSettingsHash($baseSettings);
    $parentJobId = Uuid::v4();
    $parentAssets = [
        'original_video_id' => 'drive-file-original',
        'text_asset_id' => 'drive-file-transcript',
        'completed_shorts' => ['short-a', 'short-b'],
    ];

    insertSeedJob(
        $pdo,
        $parentJobId,
        $fileHash,
        $settingsHash,
        JobStatus::COMPLETED,
        'valid',
        $baseSettings,
        $parentAssets,
        gmdate('Y-m-d H:i:s', time() - 120)
    );

    // Use same semantic settings with different key order. Server must recalculate hash.
    $requestSettings = [
        'advanced' => ['min_length_sec' => 20, 'silence_threshold' => -28],
        'extraction_count' => 3,
        'enable_subtitles' => true,
        'retention_days' => 7,
    ];

    $init = requestJson('POST', '/api/jobs/init', [
        'file_hash' => $fileHash,
        'original_file_name' => 'exact-duplicate.mp4',
        'settings' => $requestSettings,
    ]);

    assertTrue($init['status'] === 200, 'Exact duplicate init must return HTTP 200. got=' . json_encode($init));
    assertTrue(array_key_exists('upload_url', $init['json']) && $init['json']['upload_url'] === null, 'Exact duplicate init must return upload_url null. got=' . json_encode($init));
    assertTrue(($init['json']['status'] ?? '') === JobStatus::COMPLETED, 'Exact duplicate init must be completed. got=' . json_encode($init));
    assertTrue(is_string($init['json']['job_id'] ?? null), 'Exact duplicate init must return job_id.');

    $childJobId = (string) $init['json']['job_id'];
    $resolved = $repository->findJobWithResolvedAssets($childJobId);
    assertTrue($resolved !== null, 'Created child job must exist.');
    assertTrue(
        ($resolved['assets']['original_video_id'] ?? null) === $parentAssets['original_video_id']
        && ($resolved['assets']['text_asset_id'] ?? null) === $parentAssets['text_asset_id']
        && ($resolved['assets']['completed_shorts'] ?? null) === $parentAssets['completed_shorts'],
        'GET resolution must return parent assets for source-linked job. got=' . json_encode($resolved['assets'])
    );

    $get = requestJson('GET', '/api/jobs/' . $childJobId);
    assertTrue($get['status'] === 200, 'GET job for exact duplicate must return HTTP 200.');
    assertTrue(
        ($get['json']['assets']['original_video_id'] ?? null) === $parentAssets['original_video_id']
        && ($get['json']['assets']['text_asset_id'] ?? null) === $parentAssets['text_asset_id']
        && ($get['json']['assets']['completed_shorts'] ?? null) === $parentAssets['completed_shorts'],
        'GET API must transparently resolve parent assets.'
    );
}

function testInitPartialDuplicate(PDO $pdo): void
{
    resetVideoJobs($pdo);

    $fileHash = hash('sha256', 'same-video-different-settings');
    $parentSettings = ['retention_days' => 7, 'enable_subtitles' => true, 'extraction_count' => 2];
    $parentSettingsHash = canonicalSettingsHash($parentSettings);
    $parentJobId = Uuid::v4();

    insertSeedJob(
        $pdo,
        $parentJobId,
        $fileHash,
        $parentSettingsHash,
        JobStatus::COMPLETED,
        'valid',
        $parentSettings,
        ['original_video_id' => 'source-video', 'text_asset_id' => 'source-text', 'completed_shorts' => []],
        gmdate('Y-m-d H:i:s', time() - 240)
    );

    $differentSettings = ['retention_days' => 3, 'enable_subtitles' => false, 'extraction_count' => 5];
    $init = requestJson('POST', '/api/jobs/init', [
        'file_hash' => $fileHash,
        'original_file_name' => 'partial-duplicate.mp4',
        'settings' => $differentSettings,
    ]);

    assertTrue($init['status'] === 200, 'Partial duplicate init must return HTTP 200.');
    assertTrue(array_key_exists('upload_url', $init['json']) && $init['json']['upload_url'] === null, 'Partial duplicate init must return upload_url null.');
    assertTrue(($init['json']['status'] ?? '') === JobStatus::PENDING, 'Partial duplicate init must stay pending.');
}

try {
    $pdo = PdoFactory::createFromEnv();
    $migration = file_get_contents(dirname(__DIR__) . '/src/Infrastructure/Db/Migrations/001_create_video_jobs.sql');
    if ($migration === false) {
        throw new TestFailed('Failed to load migration SQL.');
    }
    $pdo->exec($migration);
    $repository = new JobRepository($pdo);

    testInitExactDuplicateAndTransparentGet($pdo, $repository);
    testInitPartialDuplicate($pdo);

    echo "OK: Issue #2 quality tests passed." . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, "FAILED: {$e->getMessage()}" . PHP_EOL);
    exit(1);
}
