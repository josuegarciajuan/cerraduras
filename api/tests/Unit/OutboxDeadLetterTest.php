<?php
declare(strict_types=1);

/**
 * Guard tests for the outbox_vb6 dead-letter queue (N6 / RF-60).
 *
 * These are source-level guards: they read the implementation files and assert
 * that poison messages (permanent 4xx from WS-VB6) are parked in the terminal
 * 'DEAD' state and exposed as informative health, so a future edit cannot
 * silently restore the "FAILED forever => degraded forever" behaviour.
 *
 * Checks:
 *   (a) OutboxVb6Repository uses 'DEAD' in markPermanentlyFailed and at the
 *       markFailed retry ceiling (nextAttempts >= 20).
 *   (b) OutboxVb6Repository::scheduleRetry re-arms 'DEAD' items too.
 *   (c) HealthController::deep exposes `outbox_dead` and does NOT set
 *       $allOk = false for the dead-letter queue (informative, not degrading).
 *   (d) Migration 0115 exists and widens the status enum with 'DEAD'.
 *
 * Run:
 *   php api/tests/Unit/OutboxDeadLetterTest.php
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

/**
 * Extract the body of a public method, from `function <name>` up to the next
 * `public function` (these methods contain no nested functions/closures).
 */
function methodBody(string $source, string $name): ?string
{
    $marker = 'public function ' . $name . '(';
    $start = strpos($source, $marker);
    if ($start === false) {
        return null;
    }
    $next = strpos($source, 'public function ', $start + strlen($marker));
    return $next === false ? substr($source, $start) : substr($source, $start, $next - $start);
}

$repo     = readSource('src/Domain/Debts/OutboxVb6Repository.php');
$health   = readSource('src/Http/Controllers/HealthController.php');

// ── (a) OutboxVb6Repository: terminal state is DEAD ────────────────────────
if ($repo === null) {
    fail('OutboxVb6Repository.php legible');
} else {
    $perm = methodBody($repo, 'markPermanentlyFailed');
    if ($perm === null) {
        fail('markPermanentlyFailed localizado');
    } elseif (str_contains($perm, "'DEAD'") && !str_contains($perm, "'FAILED'")) {
        pass('markPermanentlyFailed usa el estado terminal DEAD (y no FAILED)');
    } else {
        fail("markPermanentlyFailed NO usa 'DEAD' como estado terminal");
    }

    $failed = methodBody($repo, 'markFailed');
    if ($failed === null) {
        fail('markFailed localizado');
    } elseif (str_contains($failed, ">= 20") && str_contains($failed, "'DEAD'")) {
        pass("markFailed mueve a 'DEAD' al alcanzar el techo de 20 intentos");
    } else {
        fail("markFailed NO mueve a 'DEAD' en nextAttempts >= 20");
    }
}

// ── (b) scheduleRetry re-arms DEAD items ──────────────────────────────────
if ($repo === null) {
    fail('OutboxVb6Repository.php legible (scheduleRetry)');
} else {
    $retry = methodBody($repo, 'scheduleRetry');
    if ($retry === null) {
        fail('scheduleRetry localizado');
    } elseif (str_contains($retry, "'PENDING','FAILED','DEAD'")) {
        pass('scheduleRetry incluye DEAD entre los estados reencolables');
    } else {
        fail("scheduleRetry NO incluye DEAD en el filtro status IN (...)");
    }
}

// ── (c) HealthController::deep: outbox_dead informativo, no degrada ───────
if ($health === null) {
    fail('HealthController.php legible');
} else {
    if (str_contains($health, "'outbox_dead'")) {
        pass('HealthController::deep expone outbox_dead');
    } else {
        fail('HealthController::deep NO expone outbox_dead');
    }

    // Isolate the outbox_dead block: from the checks key up to the next section.
    $start = strpos($health, "'outbox_dead'");
    $end   = strpos($health, '// 3.', $start === false ? 0 : $start);
    $block = ($start === false)
        ? ''
        : substr($health, $start, $end === false ? null : $end - $start);

    if ($block !== '' && !str_contains($block, '$allOk')) {
        pass('outbox_dead NO altera $allOk (la cola muerta no degrada el servicio)');
    } else {
        fail('outbox_dead modifica $allOk o no se pudo aislar el bloque');
    }
}

// ── (d) Migration 0115 widens the enum ────────────────────────────────────
$migrations = glob(__DIR__ . '/../../migrations/0115_*.sql') ?: [];
if (count($migrations) === 0) {
    fail('migración 0115_*.sql presente');
} else {
    pass('migración 0115_*.sql presente: ' . basename($migrations[0]));
    $sql = file_get_contents($migrations[0]) ?: '';
    if (str_contains($sql, 'MODIFY') && str_contains($sql, "ENUM('PENDING','SENDING','SENT','FAILED','DEAD')")) {
        pass('0115 amplía el enum de status con DEAD');
    } else {
        fail('0115 NO amplía el enum de status con DEAD');
    }
    if (preg_match("/SET\s+`?status`?\s*=\s*'DEAD'.*client_error/si", $sql) === 1) {
        pass('0115 reclasifica los venenos existentes (client_error) a DEAD');
    } else {
        fail('0115 NO reclasifica los venenos existentes a DEAD');
    }
}

echo "\n" . str_repeat('=', 60) . "\n";
echo "Results: {$PASS} passed, {$FAIL} failed\n";
exit($FAIL > 0 ? 1 : 0);
