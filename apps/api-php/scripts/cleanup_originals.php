<?php

declare(strict_types=1);

use App\Infrastructure\Db\PdoFactory;

require dirname(__DIR__) . '/bootstrap.php';

/**
 * Why: completed_at is the lifecycle source of truth for retention expiry.
 *
 * @return list<array{job_id:string, assets_json:?string}>
 */
function cleanupOriginalsFetchExpiredJobs(PDO $pdo, int $retentionDays, int $batchLimit): array
{
    $retentionDays = max(1, $retentionDays);
    $batchLimit = max(1, $batchLimit);

    $sql = sprintf(
        "SELECT job_id, assets AS assets_json
         FROM video_jobs
         WHERE status = 'completed'
           AND completed_at IS NOT NULL
           AND completed_at <= (UTC_TIMESTAMP() - INTERVAL %d DAY)
         ORDER BY completed_at ASC
         LIMIT %d",
        $retentionDays,
        $batchLimit
    );
    $stmt = $pdo->query($sql);
    $rows = $stmt === false ? [] : $stmt->fetchAll();

    return array_map(
        static fn(array $row): array => [
            'job_id' => (string) $row['job_id'],
            'assets_json' => $row['assets_json'] === null ? null : (string) $row['assets_json'],
        ],
        $rows ?: []
    );
}

/**
 * @return array<string,mixed>
 */
function cleanupOriginalsDecodeAssets(?string $assetsJson): array
{
    if ($assetsJson === null || trim($assetsJson) === '') {
        return [];
    }

    $decoded = json_decode($assetsJson, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * @param array<string,mixed> $assets
 */
function cleanupOriginalsResolveOriginalVideoId(array $assets): ?string
{
    $raw = $assets['original_video_id'] ?? null;
    if (!is_string($raw)) {
        return null;
    }
    $trimmed = trim($raw);
    return $trimmed === '' ? null : $trimmed;
}

/**
 * @param array<string,mixed> $assets
 * @return array<string,mixed>
 */
function cleanupOriginalsNullifyOriginalVideoId(array $assets): array
{
    // Why: contract requires touching only original_video_id and preserving other assets keys.
    $assets['original_video_id'] = null;
    return $assets;
}

function cleanupOriginalsBuildGoogleAccessToken(): string
{
    $rawServiceAccountKey = getenv('GCP_SERVICE_ACCOUNT_KEY');
    if (is_string($rawServiceAccountKey) && trim($rawServiceAccountKey) !== '') {
        return cleanupOriginalsCreateServiceAccountAccessToken($rawServiceAccountKey);
    }

    $legacy = getenv('GOOGLE_OAUTH_ACCESS_TOKEN');
    if (is_string($legacy) && trim($legacy) !== '') {
        return trim($legacy);
    }

    throw new RuntimeException('Missing Google auth env. Set GCP_SERVICE_ACCOUNT_KEY or GOOGLE_OAUTH_ACCESS_TOKEN.');
}

function cleanupOriginalsCreateServiceAccountAccessToken(string $rawServiceAccountKey): string
{
    $serviceAccount = json_decode($rawServiceAccountKey, true);
    if (!is_array($serviceAccount)) {
        throw new RuntimeException('GCP_SERVICE_ACCOUNT_KEY must be valid JSON.');
    }

    $clientEmail = $serviceAccount['client_email'] ?? null;
    $privateKey = $serviceAccount['private_key'] ?? null;
    if (!is_string($clientEmail) || !is_string($privateKey) || $clientEmail === '' || $privateKey === '') {
        throw new RuntimeException('GCP_SERVICE_ACCOUNT_KEY is missing required fields.');
    }

    $issuedAt = time();
    $headerJson = json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES);
    $claimsJson = json_encode([
        'iss' => $clientEmail,
        'scope' => 'https://www.googleapis.com/auth/drive',
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $issuedAt,
        'exp' => $issuedAt + 3600,
    ], JSON_UNESCAPED_SLASHES);
    if (!is_string($headerJson) || !is_string($claimsJson)) {
        throw new RuntimeException('Failed to encode JWT payload.');
    }

    $signingInput = cleanupOriginalsBase64UrlEncode($headerJson) . '.' . cleanupOriginalsBase64UrlEncode($claimsJson);
    $privateKeyResource = openssl_pkey_get_private($privateKey);
    if ($privateKeyResource === false) {
        throw new RuntimeException('Failed to parse private key.');
    }

    $signature = '';
    $signOk = openssl_sign($signingInput, $signature, $privateKeyResource, OPENSSL_ALGO_SHA256);
    if (PHP_VERSION_ID < 80000) {
        openssl_free_key($privateKeyResource);
    }
    if ($signOk !== true) {
        throw new RuntimeException('Failed to sign Google JWT assertion.');
    }

    $jwtAssertion = $signingInput . '.' . cleanupOriginalsBase64UrlEncode($signature);
    $body = http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $jwtAssertion,
    ]);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    if ($ch === false) {
        throw new RuntimeException('Failed to initialize cURL for token exchange.');
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
    ]);

    $response = curl_exec($ch);
    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Google token exchange request failed: ' . $error);
    }
    $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($statusCode < 200 || $statusCode >= 300) {
        throw new RuntimeException('Google token exchange returned HTTP ' . $statusCode);
    }

    $decoded = json_decode($response, true);
    $accessToken = $decoded['access_token'] ?? null;
    if (!is_string($accessToken) || $accessToken === '') {
        throw new RuntimeException('Google token response missing access_token.');
    }
    return $accessToken;
}

function cleanupOriginalsBase64UrlEncode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

/**
 * @return 'deleted'|'skipped_not_found'
 */
function cleanupOriginalsDeleteDriveFile(string $accessToken, string $fileId): string
{
    $url = sprintf('https://www.googleapis.com/drive/v3/files/%s?supportsAllDrives=true', rawurlencode($fileId));
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Failed to initialize cURL for Drive delete.');
    }

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'DELETE',
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
        CURLOPT_RETURNTRANSFER => true,
    ]);
    $response = curl_exec($ch);
    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Drive delete request failed: ' . $error);
    }
    $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($statusCode === 404) {
        // Why: reruns must treat already-removed files as success to keep cron idempotent.
        return 'skipped_not_found';
    }
    if ($statusCode < 200 || $statusCode >= 300) {
        throw new RuntimeException('Drive delete returned HTTP ' . $statusCode . '.');
    }

    return 'deleted';
}

/**
 * @return 'deleted'|'skipped_no_id'|'skipped_not_found'
 */
function cleanupOriginalsProcessOne(PDO $pdo, string $jobId, string $accessToken): string
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT assets FROM video_jobs WHERE job_id = :job_id FOR UPDATE');
        $stmt->execute([':job_id' => $jobId]);
        $row = $stmt->fetch();
        if ($row === false) {
            $pdo->rollBack();
            return 'skipped_no_id';
        }

        $assets = cleanupOriginalsDecodeAssets($row['assets'] === null ? null : (string) $row['assets']);
        $originalVideoId = cleanupOriginalsResolveOriginalVideoId($assets);

        if ($originalVideoId === null) {
            $mergedAssets = cleanupOriginalsNullifyOriginalVideoId($assets);
            $update = $pdo->prepare('UPDATE video_jobs SET assets = :assets WHERE job_id = :job_id');
            $update->execute([
                ':assets' => json_encode($mergedAssets, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':job_id' => $jobId,
            ]);
            $pdo->commit();
            return 'skipped_no_id';
        }

        $deleteResult = cleanupOriginalsDeleteDriveFile($accessToken, $originalVideoId);
        $mergedAssets = cleanupOriginalsNullifyOriginalVideoId($assets);
        $update = $pdo->prepare('UPDATE video_jobs SET assets = :assets WHERE job_id = :job_id');
        $update->execute([
            ':assets' => json_encode($mergedAssets, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':job_id' => $jobId,
        ]);
        $pdo->commit();

        return $deleteResult === 'deleted' ? 'deleted' : 'skipped_not_found';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function cleanupOriginalsEnvInt(string $name, int $default): int
{
    $raw = getenv($name);
    if (!is_string($raw) || trim($raw) === '') {
        return $default;
    }
    $value = (int) trim($raw);
    if ($value <= 0) {
        throw new RuntimeException($name . ' must be positive integer.');
    }
    return $value;
}

function cleanupOriginalsMain(): void
{
    $retentionDays = cleanupOriginalsEnvInt('RETENTION_DAYS', 7);
    $batchLimit = cleanupOriginalsEnvInt('CLEANUP_BATCH_LIMIT', 200);

    $pdo = PdoFactory::createFromEnv();
    $accessToken = cleanupOriginalsBuildGoogleAccessToken();
    $targets = cleanupOriginalsFetchExpiredJobs($pdo, $retentionDays, $batchLimit);

    $deleted = 0;
    $skippedNoId = 0;
    $skippedNotFound = 0;
    foreach ($targets as $target) {
        $outcome = cleanupOriginalsProcessOne($pdo, $target['job_id'], $accessToken);
        if ($outcome === 'deleted') {
            $deleted++;
            continue;
        }
        if ($outcome === 'skipped_not_found') {
            $skippedNotFound++;
            continue;
        }
        $skippedNoId++;
    }

    echo json_encode([
        'retention_days' => $retentionDays,
        'scanned' => count($targets),
        'deleted' => $deleted,
        'skipped_no_id' => $skippedNoId,
        'skipped_not_found' => $skippedNotFound,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        cleanupOriginalsMain();
    } catch (Throwable $e) {
        fwrite(STDERR, 'FAILED: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
}
