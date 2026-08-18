<?php
declare(strict_types=1);

namespace Ws\Stays;

use Ws\Support\Config;
use Ws\Support\Errors\UnprocessableException;
use Ws\Vb6Repo\Vb6LogUsuariosRepo;

/**
 * StayEventsSyncService: processes stay events from the API outbox worker.
 *
 * Supported topics:
 *   stay.closed   → tipo = 'EXIT'
 *   stay.overstay → tipo = 'OUT'
 *
 * Writes to bs2026.log_usuarios using the rfid from APP_VB6_SYSTEM_RFID.
 *
 * Validation:
 *   - rfid empty or >10 chars → 422 invalid_config
 *   - unknown topic → 422 unknown_topic
 *
 * See design.md §19.2, contracts.md §3.3.
 */
final class StayEventsSyncService
{
    private Vb6LogUsuariosRepo $logRepo;

    private const TOPIC_MAP = [
        'stay.closed'   => 'EXIT',
        'stay.overstay' => 'OUT',
    ];

    public function __construct(Vb6LogUsuariosRepo $logRepo)
    {
        $this->logRepo = $logRepo;
    }

    /**
     * Process a stay event payload.
     *
     * @param array<string,mixed> $payload
     * @return array{logged:bool,topic:string,tipo:string,rfid:string,fecha:string}
     * @throws UnprocessableException
     */
    public function process(array $payload): array
    {
        // Validate rfid config — read fresh from environment each time to support test overrides
        $envRfid = getenv('APP_VB6_SYSTEM_RFID');
        // If set in env (even empty string), use that value; otherwise fall back to Config
        if ($envRfid !== false) {
            $rfid = (string) $envRfid;
        } else {
            $rfid = Config::get('APP_VB6_SYSTEM_RFID', 'QRSYS') ?? 'QRSYS';
        }
        if ($rfid === '' || mb_strlen($rfid) > 10) {
            throw new UnprocessableException(
                'invalid_config',
                'APP_VB6_SYSTEM_RFID is empty or exceeds 10 characters',
                ['rfid' => $rfid]
            );
        }

        // Validate topic
        $topic = isset($payload['topic']) ? (string) $payload['topic'] : '';
        if (!isset(self::TOPIC_MAP[$topic])) {
            throw new UnprocessableException(
                'unknown_topic',
                'Unsupported stay event topic',
                ['topic' => $topic, 'supported' => array_keys(self::TOPIC_MAP)]
            );
        }

        $tipo = self::TOPIC_MAP[$topic];

        // Resolve fecha
        $fechaRaw = isset($payload['occurred_at']) ? (string) $payload['occurred_at'] : '';
        if ($fechaRaw === '') {
            $fechaRaw = gmdate('Y-m-d H:i:s');
        } else {
            // Parse ISO8601 and convert to MySQL DATETIME
            $ts = strtotime($fechaRaw . (strpos($fechaRaw, 'T') !== false ? '' : ' UTC'));
            $fechaRaw = $ts !== false ? gmdate('Y-m-d H:i:s', $ts) : gmdate('Y-m-d H:i:s');
        }

        // Insert log
        $this->logRepo->insertLog($rfid, $fechaRaw, $tipo);

        return [
            'logged' => true,
            'topic'  => $topic,
            'tipo'   => $tipo,
            'rfid'   => $rfid,
            'fecha'  => $fechaRaw,
        ];
    }
}
