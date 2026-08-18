<?php
declare(strict_types=1);

namespace Ws\Pricing;

use PDO;
use Ws\Support\Config;
use Ws\Support\Errors\UnprocessableException;

/**
 * DebtPricing: calculates the overstay debt amount.
 *
 * Algorithms (design.md §18):
 *   A_FAIR:
 *     n_horas  = floor(exceso / 60)
 *     resto    = exceso - n_horas * 60
 *     n_medias = (resto > 0) ? 1 : 0
 *     importe  = n_horas * precio_hora + n_medias * precio_media
 *
 *   B_LITERAL:
 *     if exceso <= 30:  n_medias=1, n_horas=0
 *     if exceso <= 60:  n_horas=1, n_medias=0
 *     if exceso <= 90:  n_horas=1, n_medias=1
 *     if exceso <= 120: n_horas=2, n_medias=0
 *     general: ceil(exceso/60) hours if exceso>30, else 1 media
 *
 * Importe is capped at 32767 (SMALLINT max).
 */
final class DebtPricing
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Calculate the debt amount for the given overstay minutes.
     *
     * @param int      $excesoMinutos Overstay in minutes (>0)
     * @param int|null $codartHora    Optional override for hora article codart
     * @param int|null $codartMedia   Optional override for media article codart
     * @param string   $roundingMode  A_FAIR or B_LITERAL
     * @return array{importe:int,detalle:array<string,mixed>}
     * @throws UnprocessableException if articles cannot be resolved
     */
    public function calcularImporteDeuda(
        int    $excesoMinutos,
        ?int   $codartHora  = null,
        ?int   $codartMedia = null,
        string $roundingMode = 'A_FAIR'
    ): array {
        // Resolve articles
        [$codartHoraRes, $precioHora, $codartMediaRes, $precioMedia] =
            $this->resolveArticulos($codartHora, $codartMedia);

        // Calculate based on rounding mode
        if ($roundingMode === 'B_LITERAL') {
            [$nHoras, $nMedias] = $this->calcBLiteral($excesoMinutos);
        } else {
            [$nHoras, $nMedias] = $this->calcAFair($excesoMinutos);
        }

        $importe = $nHoras * $precioHora + $nMedias * $precioMedia;
        $overflow = false;
        if ($importe > 32767) {
            $importe = 32767;
            $overflow = true;
        }

        return [
            'importe' => $importe,
            'detalle' => [
                'exceso_minutos'  => $excesoMinutos,
                'n_horas'         => $nHoras,
                'n_medias'        => $nMedias,
                'codart_hora'     => $codartHoraRes,
                'precio_hora'     => $precioHora,
                'codart_media'    => $codartMediaRes,
                'precio_media'    => $precioMedia,
                'rounding_mode'   => $roundingMode,
                'amount_overflow' => $overflow,
            ],
        ];
    }

    /** A_FAIR algorithm: floor-based */
    private function calcAFair(int $exceso): array
    {
        $nHoras  = (int) floor($exceso / 60);
        $resto   = $exceso - $nHoras * 60;
        $nMedias = $resto > 0 ? 1 : 0;
        return [$nHoras, $nMedias];
    }

    /** B_LITERAL algorithm: literal interpretation (more expensive) */
    private function calcBLiteral(int $exceso): array
    {
        if ($exceso <= 30) {
            return [0, 1];
        }
        // Ceil division by 60
        $nHoras  = (int) ceil($exceso / 60);
        $nMedias = 0;
        return [$nHoras, $nMedias];
    }

    /**
     * Resolve articles from config or DB.
     *
     * @return array{0:int,1:int,2:int,3:int} [codartHora, precioHora, codartMedia, precioMedia]
     * @throws UnprocessableException
     */
    private function resolveArticulos(?int $codartHoraOverride, ?int $codartMediaOverride): array
    {
        // 1. From .env overrides if both present
        $envHour = Config::getInt('APP_PRICING_CODART_HOUR');
        $envHalf = Config::getInt('APP_PRICING_CODART_HALFHOUR');

        $codartHoraReq  = $codartHoraOverride  ?? $envHour;
        $codartMediaReq = $codartMediaOverride ?? $envHalf;

        if ($codartHoraReq !== null && $codartMediaReq !== null) {
            // Load from DB by codart
            $horaRow  = $this->fetchArticulo($codartHoraReq);
            $mediaRow = $this->fetchArticulo($codartMediaReq);
        } else {
            // 2. Auto-resolve from articulos table
            $horaRow  = $this->fetchArticuloByTiempo(60);
            $mediaRow = $this->fetchArticuloByTiempo(30);
        }

        if ($horaRow === null || $mediaRow === null) {
            throw new UnprocessableException(
                'pricing_invalid_article',
                'Could not resolve pricing articles for hora/media hora',
                ['codart_hora' => $codartHoraReq, 'codart_media' => $codartMediaReq]
            );
        }

        return [
            (int) $horaRow['codart'],
            (int) $horaRow['precio'],
            (int) $mediaRow['codart'],
            (int) $mediaRow['precio'],
        ];
    }

    /** @return array<string,mixed>|null */
    private function fetchArticulo(int $codart): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT codart, tiempo, precio FROM articulos WHERE codart = :c LIMIT 1'
        );
        $stmt->execute([':c' => $codart]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    private function fetchArticuloByTiempo(int $tiempo): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT codart, tiempo, precio FROM articulos WHERE tiempo = :t ORDER BY codart ASC LIMIT 1'
        );
        $stmt->execute([':t' => $tiempo]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }
}
