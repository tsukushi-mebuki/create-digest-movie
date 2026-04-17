<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require dirname(__DIR__) . '/scripts/cleanup_originals.php';

final class TestFailed extends RuntimeException
{
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new TestFailed($message);
    }
}

function testCleanupScriptContract(): void
{
    $path = dirname(__DIR__) . '/scripts/cleanup_originals.php';
    $content = file_get_contents($path);
    if (!is_string($content)) {
        throw new TestFailed('Failed to read cleanup script.');
    }

    assertTrue(str_contains($content, "completed_at <= (UTC_TIMESTAMP() - INTERVAL"), 'completed_at based retention query is missing.');
    assertTrue(str_contains($content, "cleanupOriginalsEnvInt('RETENTION_DAYS', 7)"), 'RETENTION_DAYS default 7 wiring is missing.');
    assertTrue(str_contains($content, "cleanupOriginalsNullifyOriginalVideoId"), 'original_video_id nullification helper is missing.');
    assertTrue(str_contains($content, "if (\$statusCode === 404)"), 'Drive 404 idempotent skip handling is missing.');
    assertTrue(str_contains($content, "'skipped_no_id'"), 'Missing idempotent skipped_no_id outcome.');
}

function testAssetsPartialMergeOnlyTouchesOriginalVideoId(): void
{
    $before = [
        'original_video_id' => 'orig-1',
        'text_asset_id' => 'text-1',
        'completed_shorts' => [
            ['drive_file_id' => 'short-1'],
        ],
    ];
    $after = cleanupOriginalsNullifyOriginalVideoId($before);

    assertTrue(array_key_exists('original_video_id', $after), 'original_video_id key must stay present.');
    assertTrue($after['original_video_id'] === null, 'original_video_id must be nulled.');
    assertTrue(($after['text_asset_id'] ?? null) === 'text-1', 'text_asset_id must be preserved.');
    assertTrue(($after['completed_shorts'][0]['drive_file_id'] ?? null) === 'short-1', 'completed_shorts must be preserved.');
}

function testOriginalVideoIdResolutionTreatsEmptyAsSkip(): void
{
    assertTrue(cleanupOriginalsResolveOriginalVideoId([]) === null, 'Missing original_video_id should resolve to null.');
    assertTrue(cleanupOriginalsResolveOriginalVideoId(['original_video_id' => '']) === null, 'Empty original_video_id should resolve to null.');
    assertTrue(cleanupOriginalsResolveOriginalVideoId(['original_video_id' => '  ']) === null, 'Whitespace original_video_id should resolve to null.');
    assertTrue(cleanupOriginalsResolveOriginalVideoId(['original_video_id' => 'abc']) === 'abc', 'Valid original_video_id should be returned.');
}

try {
    testCleanupScriptContract();
    testAssetsPartialMergeOnlyTouchesOriginalVideoId();
    testOriginalVideoIdResolutionTreatsEmptyAsSkip();
    echo "OK: Issue #8 quality tests passed." . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, "FAILED: {$e->getMessage()}" . PHP_EOL);
    exit(1);
}
