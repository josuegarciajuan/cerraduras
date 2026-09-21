<?php
declare(strict_types=1);

/**
 * Guard tests for the reset cleanup + request log sanity (Fase saneamiento logs).
 *
 * These are source-level guards: they read the implementation files and assert
 * that the broken/orphan SQL is gone, so a future edit cannot silently
 * reintroduce it.
 *
 * Checks:
 *   - QrTestController must clean `outbox_vb6` (the real table) and must NOT
 *     reference the non-existent `overstay_debts` nor the broken
 *     `DELETE FROM outbox ` statement (real table is `outbox_vb6`).
 *   - RequestLogMiddleware must truncate `route` with mb_substr to keep
 *     api.log from being inflated by multi-KB scanner URLs.
 *
 * Run:
 *   php tests/Unit/ResetCleanupTest.php
 */

$PASS = 0; $FAIL = 0;

function pass(string $msg): void { global $PASS; $PASS++; echo "  PASS  {$msg}\n"; }
function fail(string $msg): void { global $FAIL; $FAIL++; echo "  FAIL  {$msg}\n"; }

/**
 * Read a project source file, or null when it is missing/unreadable.
 */
function readSource(string $relative): ?string
{
    $path = __DIR__ . '/../../' . $relative;
    if (!is_file($path)) {
        return null;
    }
    $contents = file_get_contents($path);
    return $contents === false ? null : $contents;
}

$controller = readSource('src/Http/Controllers/QrTestController.php');
$middleware = readSource('src/Http/Middlewares/RequestLogMiddleware.php');

// ── QrTestController: real outbox table is referenced ──────────────────────
if ($controller === null) {
    fail('QrTestController.php legible');
} else {
    if (str_contains($controller, 'outbox_vb6')) {
        pass('QrTestController referencia outbox_vb6 (tabla real)');
    } else {
        fail('QrTestController NO referencia outbox_vb6');
    }

    // Both reset() and roomsReset() must resolve debt ids before deleting debts.
    $cleanupCount = substr_count($controller, 'cleanup outbox_vb6');
    if ($cleanupCount >= 2) {
        pass("QrTestController limpia outbox_vb6 en ambos métodos reset ({$cleanupCount})");
    } else {
        fail("QrTestController solo limpia outbox_vb6 en {$cleanupCount} método(s), se esperaban 2");
    }
}

// ── QrTestController: broken/orphan statements removed ─────────────────────
if ($controller === null) {
    fail('QrTestController.php legible (sin overstay_debts)');
} else {
    if (!str_contains($controller, 'overstay_debts')) {
        pass('QrTestController ya no referencia overstay_debts (tabla inexistente)');
    } else {
        fail('QrTestController todavía referencia overstay_debts');
    }

    // Catch the broken `DELETE FROM outbox ` (space), without matching the
    // legitimate `DELETE FROM outbox_vb6` (underscore).
    if (!str_contains($controller, 'DELETE FROM outbox ')) {
        pass('QrTestController ya no ejecuta DELETE FROM outbox (tabla inexistente)');
    } else {
        fail('QrTestController todavía ejecuta DELETE FROM outbox');
    }
}

// ── RequestLogMiddleware: route truncation ────────────────────────────────
if ($middleware === null) {
    fail('RequestLogMiddleware.php legible');
} else {
    if (str_contains($middleware, 'mb_substr')) {
        pass('RequestLogMiddleware trunca route con mb_substr');
    } else {
        fail('RequestLogMiddleware NO usa mb_substr para route');
    }

    if (str_contains($middleware, 'mb_substr((string) $request->path, 0, 512)')) {
        pass('RequestLogMiddleware trunca route a 512 caracteres');
    } else {
        fail('RequestLogMiddleware no trunca route a 512 caracteres');
    }
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "Results: {$PASS} passed, {$FAIL} failed\n";
exit($FAIL > 0 ? 1 : 0);
