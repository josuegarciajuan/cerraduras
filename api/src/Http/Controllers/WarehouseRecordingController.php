<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Request;
use App\Http\Response;
use PDO;

/**
 * WarehouseRecordingController: serves warehouse clips with HTTP Range support
 * and their posters (F64/RF-73.4).
 *
 * Routes (public LAN):
 *   GET /almacen-api/recordings/{id}/video
 *   GET /almacen-api/recordings/{id}/poster
 *
 * Path traversal is blocked by resolving the file inside api/data/cameras/.
 */
final class WarehouseRecordingController
{
    private PDO $pdo;
    private string $storageRoot;

    public function __construct(PDO $pdo, ?string $storageRoot = null)
    {
        $this->pdo = $pdo;
        $this->storageRoot = $storageRoot ?? (dirname(__DIR__, 2) . '/data/cameras');
    }

    public function video(Request $request): Response
    {
        $row = $this->findRecording((int) $request->routeParam('id'));
        if ($row === null) {
            return Response::json(404, ['error' => 'not_found']);
        }
        if ((string) $row['status'] === 'DISCARDED') {
            return Response::json(410, ['error' => 'gone']);
        }
        if ((string) $row['status'] !== 'SAVED') {
            return Response::json(404, ['error' => 'not_found']);
        }
        $abs = $this->resolveSafe((string) ($row['file_path'] ?? ''));
        if ($abs === null || !is_file($abs)) {
            return Response::json(404, ['error' => 'not_found']);
        }
        return $this->streamFile($abs, 'video/mp4');
    }

    public function poster(Request $request): Response
    {
        $row = $this->findRecording((int) $request->routeParam('id'));
        if ($row === null) {
            return Response::json(404, ['error' => 'not_found']);
        }
        $abs = $this->resolveSafe((string) ($row['poster_path'] ?? ''));
        if ($abs === null || !is_file($abs)) {
            return Response::json(404, ['error' => 'not_found']);
        }
        return $this->streamFile($abs, 'image/jpeg');
    }

    /** @return array<string,mixed>|null */
    private function findRecording(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, status, file_path, poster_path FROM camera_recordings WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function resolveSafe(string $rel): ?string
    {
        if ($rel === '') {
            return null;
        }
        $api = dirname(__DIR__, 2);
        $abs = $rel[0] === '/' ? $rel : $api . '/' . $rel;
        $real = realpath($abs);
        $root = realpath($this->storageRoot);
        if ($real === false || $root === false || strpos($real, $root) !== 0) {
            return null;
        }
        return $real;
    }

    private function streamFile(string $file, string $mime): Response
    {
        $size = filesize($file);
        if ($size === false) {
            return Response::json(500, ['error' => 'file_error']);
        }
        $etag = '"' . md5($file . $size . filemtime($file)) . '"';
        $headers = [
            'Content-Type' => $mime,
            'Accept-Ranges' => 'bytes',
            'ETag' => $etag,
            'Cache-Control' => 'private, max-age=3600',
        ];

        $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
        if (trim((string) $ifNoneMatch) === $etag) {
            return new Response(304, $headers, '');
        }

        $range = (string) ($_SERVER['HTTP_RANGE'] ?? '');
        if ($range !== '' && preg_match('/bytes=(\d*)-(\d*)/', $range, $m)) {
            $start = $m[1] === '' ? null : (int) $m[1];
            $end   = $m[2] === '' ? null : (int) $m[2];
            if ($start === null && $end !== null) {
                $start = max(0, $size - $end);
                $end = $size - 1;
            }
            $start = $start ?? 0;
            $end = ($end === null || $end >= $size) ? $size - 1 : $end;
            if ($start > $end || $start >= $size) {
                return new Response(416, $headers + ['Content-Range' => "bytes */{$size}"], '');
            }
            $length = $end - $start + 1;
            $fp = fopen($file, 'rb');
            if ($fp === false) {
                return Response::json(500, ['error' => 'file_error']);
            }
            fseek($fp, $start);
            $body = '';
            $remaining = $length;
            while ($remaining > 0 && !feof($fp)) {
                $chunk = fread($fp, min(262144, $remaining));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $body .= $chunk;
                $remaining -= strlen($chunk);
            }
            fclose($fp);
            return new Response(206, $headers + [
                'Content-Range' => "bytes {$start}-{$end}/{$size}",
                'Content-Length' => (string) $length,
            ], $body);
        }

        $body = (string) @file_get_contents($file);
        return new Response(200, $headers + ['Content-Length' => (string) $size], $body);
    }
}
