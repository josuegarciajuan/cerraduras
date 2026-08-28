<?php
declare(strict_types=1);

require __DIR__ . '/../../src/Support/Autoload.php';

use App\Http\Request;
use App\Support\TransportSecurity;

$passed = 0;
$failed = 0;
function securityCheck(bool $condition, string $message): void
{
    global $passed, $failed;
    $condition ? $passed++ : $failed++;
    echo ($condition ? "PASS " : "FAIL ") . $message . "\n";
}

function securityRequest(bool $tls, string $remoteIp, ?string $forwardedProto): Request
{
    $request = new Request();
    $request->isTls = $tls;
    $request->remoteIp = $remoteIp;
    $request->headers = $forwardedProto === null ? [] : ['x-forwarded-proto' => $forwardedProto];
    return $request;
}

echo "F40SecurityTest\n";
securityCheck(TransportSecurity::isHttps(securityRequest(true, '198.51.100.10', 'http')), 'direct TLS cannot be downgraded by a header');
securityCheck(!TransportSecurity::isHttps(securityRequest(false, '198.51.100.10', 'https')), 'untrusted forwarded HTTPS is rejected');
putenv('TRUSTED_PROXY_IPS=127.0.0.1');
securityCheck(TransportSecurity::isHttps(securityRequest(false, '127.0.0.1', 'https')), 'configured local proxy may assert terminated TLS');

exit($failed === 0 ? 0 : 1);
