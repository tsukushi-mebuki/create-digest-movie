<?php

declare(strict_types=1);

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

function requestJson(string $method, string $path, ?array $payload = null): array
{
    $url = 'http://127.0.0.1:8080' . $path;
    $ch = curl_init($url);
    if ($ch === false) {
        throw new TestFailed('Failed to initialize cURL.');
    }

    $options = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
    ];
    if ($payload !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    curl_setopt_array($ch, $options);

    $body = curl_exec($ch);
    if ($body === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new TestFailed('Request failed: ' . $error);
    }
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $json = json_decode($body, true);
    if (!is_array($json)) {
        throw new TestFailed('Response is not JSON: ' . $body);
    }

    return ['status' => $status, 'json' => $json];
}

function resetVideoJobs(PDO $pdo): void
{
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    $pdo->exec('TRUNCATE TABLE video_jobs');
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

try {
    $serviceAccount = getenv('GCP_SERVICE_ACCOUNT_KEY');
    $legacyToken = getenv('GOOGLE_OAUTH_ACCESS_TOKEN');
    if (($serviceAccount === false || trim($serviceAccount) === '') && ($legacyToken === false || trim($legacyToken) === '')) {
        throw new TestFailed('Missing Google auth env. Set GCP_SERVICE_ACCOUNT_KEY or GOOGLE_OAUTH_ACCESS_TOKEN.');
    }

    $pdo = PdoFactory::createFromEnv();
    resetVideoJobs($pdo);

    $unique = Uuid::v4();
    $payload = [
        'file_hash' => hash('sha256', 'drive-smoke-' . $unique),
        'original_file_name' => 'drive-smoke-' . $unique . '.mp4',
        'settings' => [
            'enable_subtitles' => true,
            'extraction_count' => 1,
            'retention_days' => 7,
        ],
    ];

    $response = requestJson('POST', '/api/jobs/init', $payload);
    assertTrue($response['status'] === 200, 'Expected HTTP 200. got=' . json_encode($response));

    $uploadUrl = $response['json']['upload_url'] ?? null;
    assertTrue(is_string($uploadUrl) && $uploadUrl !== '', 'upload_url must be non-empty string.');
    $parts = parse_url($uploadUrl);
    assertTrue(($parts['scheme'] ?? '') === 'https', 'upload_url must use https.');
    assertTrue(isset($parts['host']) && is_string($parts['host']) && $parts['host'] !== '', 'upload_url host is missing.');

    echo "OK: Drive connectivity smoke test passed." . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, "FAILED: {$e->getMessage()}" . PHP_EOL);
    exit(1);
}
