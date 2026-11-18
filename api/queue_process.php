<?php
/**
 * CStore — API Queue Processor (Standalone Fallback)
 * Primary processing happens automatically via check_status polling.
 * This script can be called manually via CLI or cron as a fallback:
 *   CLI:  php queue_process.php
 *   Cron: * * * * * curl -s -H "X-Queue-Token: SECRET" "https://site.com/api/queue_process.php" > /dev/null
 *         (?token=SECRET is also accepted)
 */
require_once __DIR__ . '/../config.php';

// Security: require token (skip for CLI)
if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json; charset=utf-8');
    $token = $_SERVER['HTTP_X_QUEUE_TOKEN'] ?? ($_GET['token'] ?? '');
    $expectedToken = (string)DB::setting('queue_process_token', '');
    if ($expectedToken === '' || !is_string($token) || $token === '' || !hash_equals($expectedToken, $token)) {
        http_response_code(403);
        die(json_encode(['error' => 'Invalid token']));
    }
}

// Load the shared queue functions from orders.php without running its action dispatcher
define('QUEUE_PROCESS_ONLY', true);
require_once __DIR__ . '/orders.php';

$lockFp = @fopen(api_queue_lock_path(), 'w');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    if ($lockFp) fclose($lockFp);
    die(json_encode(['status' => 'locked', 'message' => 'Another processor is running']));
}

set_time_limit(300);
$startTime = time();
$maxRuntime = 55;
$processed = 0;
$failed = 0;

try {
    while (time() - $startTime < $maxRuntime) {
        $stats = api_queue_run_batch($startTime + $maxRuntime);
        $processed += $stats['processed'];
        $failed += $stats['failed'];
        if ($stats['found'] === 0) {
            sleep(5);
        }
    }
} catch (Throwable $e) {
    error_log('[CStore] queue_process: ' . $e->getMessage());
} finally {
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
}

echo json_encode(['status' => 'ok', 'processed' => $processed, 'failed' => $failed, 'runtime' => time() - $startTime]);
