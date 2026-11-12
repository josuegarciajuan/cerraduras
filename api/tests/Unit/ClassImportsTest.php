<?php
declare(strict_types=1);

/**
 * Guardia estática: todo `use App\...;` de `src/` debe resolver a un archivo
 * real bajo `src/` (PSR-4). Evita errores tipo
 * “Class "App\Support\Qr\QrWindows" not found” que solo aparecen en runtime
 * cuando se ejecuta una rama concreta (p. ej. sala ocupada con QR).
 *
 * Run: php tests/Unit/ClassImportsTest.php
 */

$passed = 0;
$failed = 0;

function passImport(string $label): void
{
    global $passed;
    $passed++;
    echo "  PASS  {$label}\n";
}

function failImport(string $label, string $detail = ''): void
{
    global $failed;
    $failed++;
    echo "  FAIL  {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

/**
 * Extract fully-qualified imported names from a PHP file.
 *
 * @return list<string>
 */
function collectImports(string $source): array
{
    $tokens = token_get_all($source);
    $count = count($tokens);
    $imports = [];

    for ($i = 0; $i < $count; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_USE) {
            continue;
        }

        // Skip closure `function () use (...)`.
        $prev = $i - 1;
        while ($prev >= 0 && is_array($tokens[$prev]) && in_array(
            $tokens[$prev][0],
            [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT],
            true
        )) {
            $prev--;
        }
        if ($prev >= 0 && $tokens[$prev] === ')') {
            continue;
        }

        $raw = '';
        for ($j = $i + 1; $j < $count; $j++) {
            $token = $tokens[$j];
            if ($token === ';') {
                break;
            }
            $raw .= is_array($token) ? $token[1] : $token;
        }
        $i = $j;

        $raw = trim($raw);
        if ($raw === '' || str_starts_with($raw, 'function ') || str_starts_with($raw, 'const ')) {
            continue;
        }

        // Group use: `use App\Ns\{A, B as C};`
        $brace = strpos($raw, '{');
        if ($brace !== false) {
            $prefix = trim(substr($raw, 0, $brace));
            $inner = trim(substr($raw, $brace + 1));
            $inner = rtrim($inner, '} ');
            foreach (explode(',', $inner) as $part) {
                $part = trim($part);
                if ($part === '') {
                    continue;
                }
                $part = preg_split('/\s+as\s+/i', $part)[0];
                $imports[] = rtrim($prefix, '\\') . '\\' . trim($part, '\\');
            }
            continue;
        }

        $name = trim((string) preg_split('/\s+as\s+/i', $raw)[0]);
        if ($name !== '') {
            $imports[] = $name;
        }
    }

    return $imports;
}

$apiRoot = dirname(__DIR__, 2);
$srcRoot = $apiRoot . '/src';

echo "ClassImports (PSR-4)\n";

$files = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcRoot));
foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $files[] = $file->getPathname();
    }
}
sort($files);

$checked = 0;
$broken = [];
foreach ($files as $file) {
    $source = (string) file_get_contents($file);
    foreach (collectImports($source) as $import) {
        if (!str_starts_with($import, 'App\\')) {
            continue;
        }
        $checked++;
        $relative = substr($import, strlen('App\\'));
        $target = $srcRoot . '/' . str_replace('\\', '/', $relative) . '.php';
        if (!is_file($target)) {
            $broken[] = substr($file, strlen($apiRoot) + 1) . ' → ' . $import;
        }
    }
}

if ($checked > 0) {
    passImport("imports App\\ revisados: {$checked}");
} else {
    failImport('imports App\\ revisados', 'no se encontró ningún import');
}

if ($broken === []) {
    passImport('todos los imports App\\ resuelven a un archivo existente');
} else {
    foreach ($broken as $entry) {
        failImport('import App\\ sin archivo', $entry);
    }
}

echo "\nTotal: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
