<?php

declare(strict_types=1);

/**
 * Dry-run-by-default migration for an already-installed Rules module.
 *
 * Deployed usage:
 *   php /data/modules/Rules/cli/migrate-patriam.php
 *   php /data/modules/Rules/cli/migrate-patriam.php --apply
 *   php /data/modules/Rules/cli/migrate-patriam.php --self-test
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$apply = false;
$selfTest = false;
$root = dirname(__DIR__, 3);

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--apply') {
        $apply = true;
    } elseif ($argument === '--self-test') {
        $selfTest = true;
    } elseif ($argument === '--help' || $argument === '-h') {
        echo "Usage: php migrate-patriam.php [--root=/data] [--apply] [--self-test]\n";
        exit(0);
    } elseif (str_starts_with($argument, '--root=')) {
        $root = rtrim(substr($argument, 7), '/\\');
    } else {
        fwrite(STDERR, "Unknown option: $argument\n");
        exit(2);
    }
}

require_once dirname(__DIR__) . '/classes/RulesMigration.php';

if ($selfTest) {
    $failures = Rules_Migration::selfTest();
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: $failure\n");
    }

    echo $failures
        ? "Rules migration self-test failed.\n"
        : "Rules migration self-test passed: exact samples migrate, custom rows survive, and repeat runs are no-ops.\n";
    exit($failures ? 1 : 0);
}

if ($root === '' || !is_file($root . '/vendor/autoload.php')) {
    fwrite(STDERR, "NamelessMC root does not contain vendor/autoload.php: $root\n");
    exit(2);
}

define('ROOT_PATH', $root);
require ROOT_PATH . '/vendor/autoload.php';

if (!Config::exists()) {
    fwrite(STDERR, "core/config.php not found. Is --root correct?\n");
    exit(2);
}

try {
    $result = Rules_Migration::migrate($apply);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Rules migration failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

if (!$result['actions']) {
    echo "Rules content is already current; no migration is needed.\n";
    exit(0);
}

echo ($apply ? 'Applied' : 'Pending') . ' Rules migration actions:' . PHP_EOL;
foreach ($result['actions'] as $action) {
    $target = $action['id'] === null ? '' : ' row ' . $action['id'];
    echo "  - {$action['operation']} {$action['collection']}$target: {$action['summary']}\n";
}

if (!$apply) {
    echo "Dry run only; pass --apply to perform and verify these exact actions.\n";
    exit(0);
}

if (!$result['ready'] || $result['remaining']) {
    fwrite(STDERR, "Migration returned without reaching a verified current state.\n");
    exit(1);
}

echo "Rules migration applied and verified. A repeat run will be a no-op.\n";
