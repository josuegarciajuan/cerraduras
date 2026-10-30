<?php
declare(strict_types=1);

/**
 * F104.3/RF-219 — inferencia estable de polaridad de puerta.
 *
 * Run:
 *   php tests/Unit/DoorPolarityEvidenceTest.php
 */

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Domain\Presence\DoorPolarity;
use App\Domain\Presence\DoorPolarityEvidence;

$passed = 0;
$failed = 0;
function pass(string $m): void { global $passed; $passed++; echo "  ✅ {$m}\n"; }
function fail(string $m): void { global $failed; $failed++; echo "  ❌ {$m}\n"; }
function same(string $m, mixed $a, mixed $e): void
{
    if ($a === $e) { pass($m); return; }
    fail($m . ' — expected ' . var_export($e, true) . ', got ' . var_export($a, true));
}
function check(string $m, bool $c, string $d = ''): void { $c ? pass($m) : fail($m . ($d ? " — {$d}" : '')); }

function pe(string $value, mixed $raw, string $polarity, array $extra = []): array
{
    return [
        'id' => $extra['id'] ?? 1,
        'value' => $value,
        'occurred_at' => '2026-10-30T12:00:00Z',
        'discard_reason' => $extra['discard'] ?? null,
        'meta' => array_merge([
            'entity' => 'contact',
            'zigbee_value' => $raw,
            'door_polarity' => $polarity,
        ], $extra['meta'] ?? []),
    ];
}

echo "Door polarity evidence (F104.3)\n";

$latestOpen = [
    pe('OPEN', false, 'inverted', ['id' => 10]),
    pe('CLOSED', true, 'inverted', ['id' => 9]),
];
$r = DoorPolarityEvidence::infer($latestOpen, 'inverted');
same('última OPEN no cambia la polaridad invertida', $r['polarity'], 'inverted');
same('usa el último CLOSED como evidencia', $r['basis'], 'closed_event');
same('sin cambio', $r['changed'], false);
same('evento CLOSED usado', $r['event_id'], 9);

$normal = [
    pe('OPEN', true, 'normal', ['id' => 20]),
    pe('CLOSED', false, 'normal', ['id' => 19]),
];
$r = DoorPolarityEvidence::infer($normal, 'normal');
same('última OPEN no cambia la polaridad normal', $r['polarity'], 'normal');
same('normal confirmada', $r['changed'], false);

$onlyOpen = [pe('OPEN', false, 'inverted', ['id' => 30])];
$r = DoorPolarityEvidence::infer($onlyOpen, 'inverted');
same('sin CLOSED → mantiene la actual', $r['polarity'], 'inverted');
same('sin CLOSED → assumed_current', $r['basis'], 'assumed_current');
same('sin CLOSED → no cambia', $r['changed'], false);

$differentPolarity = [
    pe('OPEN', false, 'inverted', ['id' => 40]),
    pe('CLOSED', true, 'normal', ['id' => 39]),
];
$r = DoorPolarityEvidence::infer($differentPolarity, 'inverted');
same('ignora CLOSED de otra polaridad', $r['basis'], 'assumed_current');
same('mantiene invertida', $r['polarity'], 'inverted');

$bootstrap = [
    pe('CLOSED', true, 'inverted', ['id' => 50, 'discard' => 'bootstrap']),
];
$r = DoorPolarityEvidence::infer($bootstrap, 'inverted');
same('ignora bootstrap', $r['basis'], 'assumed_current');

$resync = [
    pe('CLOSED', true, 'inverted', ['id' => 51, 'meta' => ['source' => 'resync']]),
];
$r = DoorPolarityEvidence::infer($resync, 'inverted');
same('ignora resync', $r['basis'], 'assumed_current');

$otherEntity = [
    pe('CLOSED', false, 'inverted', ['id' => 52, 'meta' => ['entity' => 'presence']]),
];
$r = DoorPolarityEvidence::infer($otherEntity, 'inverted');
same('ignora entidad que no es contact', $r['basis'], 'assumed_current');

$canonicalFallback = [[
    'id' => 60,
    'value' => 'CLOSED',
    'occurred_at' => '2026-10-30T12:00:00Z',
    'discard_reason' => null,
    'meta' => ['entity' => 'contact', 'door_polarity' => 'normal',
        'canonical_before_polarity' => 'CLOSED'],
]];
$r = DoorPolarityEvidence::infer($canonicalFallback, 'normal');
same('deriva raw desde canonical_before_polarity', $r['polarity'], 'normal');

$empty = DoorPolarityEvidence::infer([], 'inverted');
same('sin eventos mantiene la actual', $empty['polarity'], 'inverted');
same('sin eventos assumed_current', $empty['basis'], 'assumed_current');

check('raw false es cerrado', DoorPolarityEvidence::isClosedRaw(false));
check('raw "false" es cerrado', DoorPolarityEvidence::isClosedRaw('false'));
check('raw 0 es cerrado', DoorPolarityEvidence::isClosedRaw(0));
check('raw true no es cerrado', !DoorPolarityEvidence::isClosedRaw(true));
check('raw "true" no es cerrado', !DoorPolarityEvidence::isClosedRaw('true'));

same('normal canónico', DoorPolarity::canonical(['door_polarity' => 'normal'], 'CLOSED'), 'CLOSED');
same('invertido canónico', DoorPolarity::canonical(['door_polarity' => 'inverted'], 'CLOSED'), 'OPEN');

echo "\nTotal: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
