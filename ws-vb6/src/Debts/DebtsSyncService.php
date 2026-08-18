<?php
declare(strict_types=1);

namespace Ws\Debts;

use PDO;
use Ws\Pricing\DebtPricing;
use Ws\Support\Config;
use Ws\Support\Errors\UnprocessableException;
use Ws\Vb6Repo\Vb6DeudasRepo;

/**
 * DebtsSyncService: orchestrates the debt creation in bs2026.
 *
 * Steps:
 *   1. Validate required vb6_refs (codtic, codcli).
 *   2. Resolve temporada (S22).
 *   3. Calculate importe via DebtPricing.
 *   4. Insert into deudas via Vb6DeudasRepo (idempotent).
 *
 * See design.md §19.1, contracts.md §3.2.
 */
final class DebtsSyncService
{
    private Vb6DeudasRepo $deudasRepo;
    private DebtPricing   $pricing;

    public function __construct(Vb6DeudasRepo $deudasRepo, DebtPricing $pricing)
    {
        $this->deudasRepo = $deudasRepo;
        $this->pricing    = $pricing;
    }

    /**
     * Process a debt.created event and insert into bs2026.deudas.
     *
     * @param array<string,mixed> $payload Decoded request body
     * @return array{inserted:bool,codtic:int,temporada:string,importe:int,detalle:array<string,mixed>}
     * @throws UnprocessableException on missing fields or pricing error
     */
    public function process(array $payload): array
    {
        // 1. Validate required refs
        if (empty($payload['codtic']) || empty($payload['codcli'])) {
            throw new UnprocessableException(
                'missing_vb6_refs',
                'Fields codtic and codcli are required',
                ['provided_keys' => array_keys($payload)]
            );
        }

        $codtic  = (int) $payload['codtic'];
        $codcli  = (int) $payload['codcli'];
        $exceso  = isset($payload['exceso_minutos']) ? (int) $payload['exceso_minutos'] : 0;
        $fecha   = isset($payload['fecha']) ? (string) $payload['fecha'] : gmdate('Y-m-d H:i:s');

        // 2. Resolve temporada (S22)
        $temporada = $this->resolveTemporada($payload['temporada'] ?? '');

        // 3. Calculate importe
        $roundingMode = Config::get('APP_PRICING_ROUNDING', 'A_FAIR') ?? 'A_FAIR';
        $codartHora   = Config::getInt('APP_PRICING_CODART_HOUR');
        $codartMedia  = Config::getInt('APP_PRICING_CODART_HALFHOUR');

        $pricingResult = $this->pricing->calcularImporteDeuda(
            $exceso,
            $codartHora,
            $codartMedia,
            $roundingMode
        );

        $importe = $pricingResult['importe'];

        // 4. Insert (idempotent)
        $inserted = $this->deudasRepo->insertDebt([
            'codtic'     => $codtic,
            'temporada'  => $temporada,
            'codcli_old' => $codcli,
            'codcli_new' => $codcli,
            'fecha'      => $fecha,
            'importe'    => $importe,
            'pagada'     => '0',
        ]);

        return [
            'inserted'   => $inserted,
            'codtic'     => $codtic,
            'temporada'  => $temporada,
            'importe'    => $importe,
            'detalle'    => $pricingResult['detalle'],
        ];
    }

    /**
     * Resolve temporada from payload or fallback (S22).
     *
     * If payload has a non-empty temporada, use it as-is.
     * Otherwise use fallback strategy from config (LOTE_ACTIVO or YEAR_NOW).
     * In mock context: always fall back to current year.
     */
    private function resolveTemporada(string $rawTemporada): string
    {
        if ($rawTemporada !== '') {
            return $rawTemporada;
        }

        // Fallback: use current year in Europe/Madrid
        $tz   = new \DateTimeZone('Europe/Madrid');
        $now  = new \DateTimeImmutable('now', $tz);
        return $now->format('Y');
    }
}
