<?php
declare(strict_types=1);

/**
 * F90 — Paquete de despliegue aislado Mosquitto + Zigbee2MQTT.
 *
 * Verificación ESTÁTICA: no levanta Docker, no usa red, no toca hardware ni
 * ejecuta deploy.sh. Solo lee los archivos del paquete y valida sintaxis.
 *
 * Trazabilidad: RF-137..RF-141 · design.md §Paquete de despliegue.
 *
 * Ejecutar: php tests/Unit/ZigbeeDeployPackageTest.php
 */

$PASS = 0;
$FAIL = 0;

function pass(string $m): void
{
    global $PASS;
    $PASS++;
    echo "  ✅ {$m}\n";
}

function fail(string $m): void
{
    global $FAIL;
    $FAIL++;
    echo "  ❌ {$m}\n";
}

echo "ZigbeeDeployPackage (F90) Static Tests\n";
echo str_repeat("=", 64) . "\n\n";

$root = dirname(__DIR__, 3);
$pkg = $root . '/deploy/zigbee';

$required = [
    'docker-compose.yml',
    '.env.example',
    '.gitignore',
    'mosquitto/mosquitto.conf.example',
    'zigbee2mqtt/configuration.yaml.example',
    'preflight.sh',
    'deploy.sh',
    'verify.sh',
    'backup.sh',
    'restore.sh',
    'rollback.sh',
    'README.md',
];

foreach ($required as $file) {
    if (is_file($pkg . '/' . $file)) {
        pass("existe {$file}");
    } else {
        fail("falta {$file}");
    }
}

$composePath = $pkg . '/docker-compose.yml';
$compose = is_file($composePath) ? (string) file_get_contents($composePath) : '';
$envExamplePath = $pkg . '/.env.example';
$envExample = is_file($envExamplePath) ? (string) file_get_contents($envExamplePath) : '';
$z2mPath = $pkg . '/zigbee2mqtt/configuration.yaml.example';
$z2m = is_file($z2mPath) ? (string) file_get_contents($z2mPath) : '';
$mosqPath = $pkg . '/mosquitto/mosquitto.conf.example';
$mosq = is_file($mosqPath) ? (string) file_get_contents($mosqPath) : '';
$readmePath = $pkg . '/README.md';
$readme = is_file($readmePath) ? (string) file_get_contents($readmePath) : '';
$gitignorePath = $pkg . '/.gitignore';
$gitignore = is_file($gitignorePath) ? (string) file_get_contents($gitignorePath) : '';

// ── 1. Prohibiciones de aislamiento ────────────────────────────────────────
$forbiddenCompose = [
    '/privileged\s*:\s*true/i' => 'privileged: true',
    '/network_mode\s*:\s*["\']?host/i' => 'network_mode: host',
    '/pid\s*:\s*["\']?host/i' => 'PID namespace del host',
    '/ipc\s*:\s*["\']?host/i' => 'IPC namespace del host',
    '/userns_mode\s*:/i' => 'userns_mode',
    '/docker\.sock/i' => 'socket Docker',
    '/^\s*-\s*["\']?\/dev(?:\/|["\']|\s*:)/m' => 'montaje de /dev',
    '/^\s*-\s*["\']?\/(?:proc|sys|root|home)(?:\/|["\']|\s*:)/m' => 'montaje del sistema/home',
    '/^\s*-\s*["\']?~(?:\/|["\']|\s*:)/m' => 'montaje del home',
];
foreach ($forbiddenCompose as $regex => $label) {
    if (preg_match($regex, $compose) === 1) {
        fail("compose no debe contener {$label}");
    } else {
        pass("compose sin {$label}");
    }
}
if (preg_match('/^\s*-\s*["\']?\/\s*:/m', $compose) === 1) {
    fail('compose no debe montar la raíz /');
} else {
    pass('compose sin montaje de la raíz /');
}

// ── 2. Puertos publicados solo en loopback ────────────────────────────────
if (str_contains($compose, '127.0.0.1:${MQTT_HOST_PORT')) {
    pass('MQTT se publica en 127.0.0.1 por defecto');
} else {
    fail('MQTT no está ligado a 127.0.0.1');
}
if (str_contains($compose, '127.0.0.1:${Z2M_UI_HOST_PORT')) {
    pass('UI Zigbee2MQTT se publica en 127.0.0.1 por defecto');
} else {
    fail('la UI no está ligada a 127.0.0.1');
}
$portLines = implode("\n", array_filter(
    preg_split('/\R/', $compose) ?: [],
    static fn (string $l): bool => trim($l) !== '' && str_starts_with(trim($l), '-')
));
foreach (['0.0.0.0:', '"[::]:', '- *:'] as $badBind) {
    if (str_contains($portLines, $badBind)) {
        fail("hay un bind no loopback en ports ({$badBind})");
    } else {
        pass("sin bind no loopback en ports ({$badBind})");
    }
}

// ── 3. Imágenes fijas y fallo cerrado ─────────────────────────────────────
foreach (['MOSQUITTO_IMAGE', 'Z2M_IMAGE'] as $imgVar) {
    if (preg_match('/image:\s*\$\{' . $imgVar . ':\?[^}]+\}/', $compose) === 1) {
        pass("imagen {$imgVar} exige valor explícito");
    } else {
        fail("imagen {$imgVar} no usa placeholder obligatorio");
    }
}
if (preg_match('/^\s*image:.*:latest\s*$/mi', $compose) === 1) {
    fail('compose usa latest en alguna imagen');
} else {
    pass('compose no usa latest');
}
foreach (['MOSQUITTO_IMAGE=', 'Z2M_IMAGE='] as $imgAssign) {
    if (str_contains($envExample, $imgAssign . '__REQUIRED_')) {
        pass(".env.example marca {$imgAssign} como placeholder inválido");
    } else {
        fail(".env.example no marca {$imgAssign} como placeholder");
    }
}

// ── 4. Hardening del Compose ──────────────────────────────────────────────
$hardening = [
    'restart: unless-stopped' => 'restart unless-stopped',
    'cap_drop:' => 'cap_drop',
    'no-new-privileges:true' => 'no-new-privileges',
    'pids_limit:' => 'límite de PIDs',
    'mem_limit:' => 'límite de memoria',
    'cpus:' => 'límite de CPU',
    'max-size:' => 'rotación de logs',
    'read_only: true' => 'read_only',
    'tmpfs:' => 'tmpfs mínimo',
    'healthcheck:' => 'healthcheck',
];
foreach ($hardening as $needle => $label) {
    if (str_contains($compose, $needle)) {
        pass("compose incluye {$label}");
    } else {
        fail("compose no incluye {$label}");
    }
}
if (substr_count($compose, 'read_only: true') >= 2 && substr_count($compose, 'restart: unless-stopped') >= 2) {
    pass('ambos servicios aplican read_only y restart');
} else {
    fail('read_only/restart no están aplicados a ambos servicios');
}

// ── 5. Zigbee2MQTT y Mosquitto ────────────────────────────────────────────
$z2mChecks = [
    '/^permit_join:\s*false\s*$/m' => 'permit_join: false',
    '/adapter:\s*ember\b/' => 'adapter: ember',
    '/baudrate:\s*115200\b/' => 'baudrate: 115200',
    '/rtscts:\s*false\b/' => 'rtscts: false',
    '/port:\s*tcp:\/\/\$\{ZIGBEE_ADAPTER_HOST\}:\$\{ZIGBEE_ADAPTER_PORT\}/' => 'adaptador TCP configurable',
];
foreach ($z2mChecks as $regex => $label) {
    if (preg_match($regex, $z2m) === 1) {
        pass("Zigbee2MQTT declara {$label}");
    } else {
        fail("Zigbee2MQTT no declara {$label}");
    }
}
if (str_contains($mosq, 'allow_anonymous false') && str_contains($mosq, 'password_file')) {
    pass('Mosquitto exige autenticación');
} else {
    fail('Mosquitto no exige autenticación');
}
if (preg_match('/^\s*password\s*:/mi', $z2m) === 1) {
    fail('configuration.yaml no debe contener una contraseña literal');
} else {
    pass('configuration.yaml sin contraseña literal');
}

// ── 6. Secretos y datos fuera de Git ──────────────────────────────────────
foreach (['.env', 'data/', 'backups/'] as $ignored) {
    if (str_contains($gitignore, $ignored)) {
        pass(".gitignore ignora {$ignored}");
    } else {
        fail(".gitignore no ignora {$ignored}");
    }
}
if (str_contains($envExample, 'MQTT_PASSWORD=__REQUIRED_MQTT_PASSWORD__')) {
    pass('.env.example usa un placeholder inválido para la contraseña');
} else {
    fail('.env.example no usa un placeholder explícito para la contraseña');
}
if (str_contains($envExample, 'ZIGBEE_ADAPTER_HOST=__REQUIRED_ADAPTER_HOST__')) {
    pass('.env.example no cablea ninguna IP de host');
} else {
    fail('.env.example cablea o no marca el host del adaptador');
}

// ── 7. Ausencia de secretos, IPs y rutas de oficina ───────────────────────
$scanned = [
    'docker-compose.yml' => $compose,
    '.env.example' => $envExample,
    '.gitignore' => $gitignore,
    'mosquitto/mosquitto.conf.example' => $mosq,
    'zigbee2mqtt/configuration.yaml.example' => $z2m,
    'README.md' => $readme,
];
foreach (glob($pkg . '/*.sh') ?: [] as $scriptPath) {
    $scanned[basename($scriptPath)] = (string) file_get_contents($scriptPath);
}
$officeIndicators = [
    '/\/root\//' => 'ruta /root',
    '/\/home\//' => 'ruta /home',
    '/\b192\.168\.\d{1,3}\.\d{1,3}\b/' => 'IP privada 192.168.x.x',
    '/\b172\.16\.\d{1,3}\.\d{1,3}\b/' => 'IP privada 172.16.x.x',
    '/\b92\.113\.151\.136\b/' => 'IP de oficina conocida',
    '/[A-Za-z]:\\\\Users\\\\/' => 'ruta de Windows',
];
foreach ($scanned as $name => $content) {
    foreach ($officeIndicators as $regex => $label) {
        if (preg_match($regex, $content) === 1) {
            fail("{$name} contiene {$label}");
        } else {
            pass("{$name} sin {$label}");
        }
    }
}
$secretIndicators = [
    '/BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY/' => 'clave privada',
    '/\bsk-[A-Za-z0-9]{20,}/' => 'clave estilo API',
    '/\bAKIA[0-9A-Z]{16}\b/' => 'credencial AWS',
];
foreach ($scanned as $name => $content) {
    foreach ($secretIndicators as $regex => $label) {
        if (preg_match($regex, $content) === 1) {
            fail("{$name} contiene una {$label}");
        } else {
            pass("{$name} sin {$label}");
        }
    }
}

// ── 8. Contrato de los scripts ────────────────────────────────────────────
$scripts = [
    'preflight.sh' => false,
    'deploy.sh' => false,
    'verify.sh' => false,
    'backup.sh' => false,
    'restore.sh' => false,
    'rollback.sh' => false,
];
foreach (array_keys($scripts) as $script) {
    $path = $pkg . '/' . $script;
    $src = is_file($path) ? (string) file_get_contents($path) : '';
    if (preg_match('/^set -eu$/m', $src) === 1) {
        pass("{$script} usa set -eu");
    } else {
        fail("{$script} no usa set -eu");
    }
    if (is_file($path) && is_executable($path)) {
        pass("{$script} es ejecutable");
    } else {
        fail("{$script} no es ejecutable");
    }
    if (str_contains($src, 'down -v') || preg_match('/down\b[^\n]*--volumes/', $src) === 1) {
        fail("{$script} contiene eliminación de volúmenes");
    } else {
        pass("{$script} no elimina volúmenes");
    }
}

$preflight = (string) file_get_contents($pkg . '/preflight.sh');
$readOnlyForbidden = [
    '/docker\s+compose\s+up\b/' => 'docker compose up',
    '/docker\s+run\b/' => 'docker run',
    '/\bapt(?:-get)?\s+install\b/' => 'instalación apt',
    '/\bsystemctl\s+(?:start|restart|enable|stop)\b/' => 'cambio systemd',
    '/\bufw\s+(?:allow|enable|disable|delete)\b/' => 'cambio de firewall',
    '/\biptables\b/' => 'cambio de iptables',
];
foreach ($readOnlyForbidden as $regex => $label) {
    if (preg_match($regex, $preflight) === 1) {
        fail("preflight.sh no es de solo lectura: {$label}");
    } else {
        pass("preflight.sh sin {$label}");
    }
}

$deploy = (string) file_get_contents($pkg . '/deploy.sh');
$deployChecks = [
    'preflight.sh' => 'invoca preflight',
    'MQTT_PASSWORD' => 'valida la contraseña',
    'up -d' => 'levanta el proyecto',
    '--project-directory' => 'fija el directorio del proyecto',
    '[ ! -e "$MOSQ_CONF" ]' => 'no sobrescribe la configuración Mosquitto',
    '[ ! -e "$Z2M_CONF" ]' => 'no sobrescribe la configuración Zigbee2MQTT',
    '[ ! -f "$PASSWD_FILE" ]' => 'no sobrescribe el fichero de contraseñas',
];
foreach ($deployChecks as $needle => $label) {
    if (str_contains($deploy, $needle)) {
        pass("deploy.sh {$label}");
    } else {
        fail("deploy.sh no {$label}");
    }
}
if (str_contains($deploy, 'docker compose') && !preg_match('/\bdown\b/', $deploy)) {
    pass('deploy.sh solo ejecuta el up de su proyecto');
} else {
    fail('deploy.sh no debe ejecutar down ni otros proyectos');
}
if (preg_match('/echo[^\n]*\$(?:\{)?MQTT_PASSWORD/', $deploy . $preflight) === 1) {
    fail('algún script imprime el valor de la contraseña');
} else {
    pass('deploy/preflight no imprimen la contraseña');
}

$verify = (string) file_get_contents($pkg . '/verify.sh');
foreach (['health', 'loopback', 'mosquitto_pub', 'mosquitto_sub', 'logs'] as $needle) {
    if (str_contains($verify, $needle)) {
        pass("verify.sh incluye {$needle}");
    } else {
        fail("verify.sh no incluye {$needle}");
    }
}
$backup = (string) file_get_contents($pkg . '/backup.sh');
if (str_contains($backup, 'sha256sum') && str_contains($backup, 'data/zigbee2mqtt')) {
    pass('backup.sh incluye datos Zigbee y sha256');
} else {
    fail('backup.sh no genera tar+sha256 de Zigbee');
}
$restore = (string) file_get_contents($pkg . '/restore.sh');
if (str_contains($restore, 'RESTORE') && str_contains($restore, 'read -r answer') && str_contains($restore, 'sha256sum -c')) {
    pass('restore.sh exige confirmación, verifica hash y usa destino nuevo');
} else {
    fail('restore.sh no cumple confirmación/hash/destino nuevo');
}
if (str_contains($restore, '[ -e "$DEST" ]')) {
    pass('restore.sh rechaza un destino existente');
} else {
    fail('restore.sh no rechaza destinos existentes');
}
$rollback = (string) file_get_contents($pkg . '/rollback.sh');
if (str_contains($rollback, 'read -r answer') && str_contains($rollback, 'ROLLBACK')) {
    pass('rollback.sh exige confirmación explícita');
} else {
    fail('rollback.sh no exige confirmación');
}

// ── 9. Sin wiring de arranque/systemd ─────────────────────────────────────
$wiringFiles = [$root . '/start-all.sh', $root . '/stop-all.sh'];
foreach (glob($root . '/docs/systemd/*') ?: [] as $unit) {
    $wiringFiles[] = $unit;
}
$wiringMarkers = ['deploy/zigbee', 'zigbee-deploy', 'cerraduras-zigbee-deploy', 'docker compose'];
foreach ($wiringFiles as $file) {
    if (!is_file($file)) {
        continue;
    }
    $src = (string) file_get_contents($file);
    $hit = null;
    foreach ($wiringMarkers as $marker) {
        if (str_contains($src, $marker)) {
            $hit = $marker;
            break;
        }
    }
    if ($hit !== null) {
        fail(basename($file) . " contiene wiring prohibido ({$hit})");
    } else {
        pass(basename($file) . ' sin wiring del paquete Zigbee');
    }
}
$units = glob($pkg . '/**/*.service') ?: [];
$units = array_merge($units, glob($pkg . '/*.service') ?: []);
if ($units === []) {
    pass('el paquete no incluye unidades systemd');
} else {
    fail('el paquete incluye unidades systemd');
}

// ── 10. Sintaxis de scripts y YAML ────────────────────────────────────────
foreach (array_keys($scripts) as $script) {
    $path = $pkg . '/' . $script;
    if (!is_file($path)) {
        continue;
    }
    $out = [];
    $rc = 0;
    exec('bash -n ' . escapeshellarg($path) . ' 2>&1', $out, $rc);
    if ($rc === 0) {
        pass("bash -n {$script}");
    } else {
        fail("bash -n {$script}: " . implode(' ', array_slice($out, 0, 3)));
    }
}

$python = trim((string) shell_exec('command -v python3 2>/dev/null'));
if ($python === '') {
    fail('python3 no disponible para validar YAML');
} else {
    $code = 'import sys, yaml; [yaml.safe_load(open(p, encoding="utf-8")) for p in sys.argv[1:]]';
    $cmd = escapeshellarg($python)
        . ' -c ' . escapeshellarg($code)
        . ' ' . escapeshellarg($composePath)
        . ' ' . escapeshellarg($z2mPath) . ' 2>&1';
    $out = [];
    $rc = 0;
    exec($cmd, $out, $rc);
    if ($rc === 0) {
        pass('YAML de compose y Zigbee2MQTT parseable con Python');
    } else {
        fail('YAML no parseable: ' . implode(' ', array_slice($out, 0, 3)));
    }
}

$shellcheck = trim((string) shell_exec('command -v shellcheck 2>/dev/null'));
if ($shellcheck === '') {
    pass('shellcheck no instalado; comprobación omitida (no se instala nada)');
} else {
    $cmd = escapeshellarg($shellcheck) . ' -S warning ' . implode(' ', array_map('escapeshellarg', array_map(
        static fn (string $s): string => $pkg . '/' . $s,
        array_keys($scripts)
    ))) . ' 2>&1';
    $out = [];
    $rc = 0;
    exec($cmd, $out, $rc);
    if ($rc === 0) {
        pass('shellcheck sin warnings');
    } else {
        fail('shellcheck: ' . implode(' ', array_slice($out, 0, 4)));
    }
}

// ── 11. README operativo ──────────────────────────────────────────────────
foreach (['No se ha desplegado', 'oficina', 'host definitivo', 'backup', 'rollback', 'loopback'] as $needle) {
    if (stripos($readme, $needle) !== false) {
        pass("README menciona {$needle}");
    } else {
        fail("README no menciona {$needle}");
    }
}

echo "\nTotal: {$PASS} passed, {$FAIL} failed\n";
exit($FAIL === 0 ? 0 : 1);
