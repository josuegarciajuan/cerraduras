<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\AccessKeys\NfcCardRepository;
use App\Http\Request;
use App\Http\Response;

/**
 * KeyDeskController: public HTML shell and scoped helper API for /llaves (F98).
 *
 * Pages are protected by CrmSessionMiddleware + KeyDeskAccessMiddleware in
 * public/index.php. This controller only serves bytes and maps the restricted
 * NFC-card listing; it never exposes card codes or key tokens.
 */
final class KeyDeskController
{
    private const PUBLIC_DIR = '/../../../public/llaves/';

    public function __construct(private NfcCardRepository $nfcCardRepo)
    {
    }

    /** GET /llaves — SPA entry point. */
    public function index(Request $request): Response
    {
        return $this->servePage('index.html');
    }

    /** GET /llaves/login — public login page. */
    public function loginPage(Request $request): Response
    {
        return $this->servePage('login.html');
    }

    /** GET /llaves/assets/{path} — public static assets (CSS/JS/icons). */
    public function asset(Request $request): Response
    {
        $path = (string) ($request->routeParam('path') ?? '');
        return $this->serveAsset($path);
    }

    /**
     * GET /api/v1/llaves/nfc-available
     *
     * Only AVAILABLE cards, only id and number. Never returns code_hash,
     * code_last4, notes or the current key.
     */
    public function nfcAvailable(Request $request): Response
    {
        $cards = $this->nfcCardRepo->findAvailable();

        $data = array_map(
            static fn(array $card): array => [
                'id'     => (int) $card['id'],
                'number' => (string) $card['number'],
            ],
            $cards
        );

        return Response::json(200, ['data' => $data]);
    }

    private function servePage(string $filename): Response
    {
        $base = realpath(__DIR__ . self::PUBLIC_DIR);
        if ($base === false) {
            return new Response(404, [], 'Not found');
        }

        $file = $base . DIRECTORY_SEPARATOR . $filename;
        if (!is_file($file)) {
            return new Response(404, [], 'Not found');
        }

        return new Response(
            200,
            ['Content-Type' => 'text/html; charset=utf-8'],
            (string) (@file_get_contents($file) ?: '')
        );
    }

    private function serveAsset(string $path): Response
    {
        // Reject traversal/absolute paths before touching the filesystem.
        $path = str_replace('\\', '/', $path);
        if (
            $path === ''
            || strpos($path, "\0") !== false
            || strpos($path, '..') !== false
            || $path[0] === '/'
        ) {
            return new Response(404, [], 'Not found');
        }

        $base = realpath(__DIR__ . self::PUBLIC_DIR . 'assets');
        if ($base === false) {
            return new Response(404, [], 'Not found');
        }

        $file = realpath($base . DIRECTORY_SEPARATOR . $path);
        if (
            $file === false
            || strpos($file, $base . DIRECTORY_SEPARATOR) !== 0
            || !is_file($file)
        ) {
            return new Response(404, [], 'Not found');
        }

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $mime = [
            'html'  => 'text/html; charset=utf-8',
            'css'   => 'text/css; charset=utf-8',
            'js'    => 'application/javascript; charset=utf-8',
            'json'  => 'application/json; charset=utf-8',
            'svg'   => 'image/svg+xml',
            'png'   => 'image/png',
            'jpg'   => 'image/jpeg',
            'jpeg'  => 'image/jpeg',
            'webp'  => 'image/webp',
            'ico'   => 'image/x-icon',
            'woff'  => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf'   => 'font/ttf',
        ][$ext] ?? 'application/octet-stream';

        return new Response(
            200,
            [
                'Content-Type' => $mime,
                'X-Content-Type-Options' => 'nosniff',
            ],
            (string) (@file_get_contents($file) ?: '')
        );
    }
}
