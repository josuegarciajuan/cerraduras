<?php declare(strict_types=1);

namespace App\Domain\Devices;

final class DevicePackItem
{
    public int $id;
    public int $packId;
    public string $kind;
    public string $externalIdPrefix;
    /** @var array<string,mixed>|null */
    public ?array $metaJson;
    public int $position;

    public const VALID_KINDS = ['RPI', 'LOCK', 'PROXIMITY', 'PRESENCE', 'SWITCH'];

    /** @param array<string,mixed> $props */
    public function __construct(array $props)
    {
        $this->id               = (int) ($props['id'] ?? 0);
        $this->packId           = (int) ($props['pack_id'] ?? 0);
        $this->kind             = (string) ($props['kind'] ?? '');
        $this->externalIdPrefix = (string) ($props['external_id_prefix'] ?? '');
        $this->position         = (int) ($props['position'] ?? 0);

        if (isset($props['meta_json']) && is_string($props['meta_json'])) {
            $decoded = json_decode($props['meta_json'], true);
            $this->metaJson = is_array($decoded) ? $decoded : null;
        } else {
            $this->metaJson = $props['meta_json'] ?? null;
        }
    }

    public static function isValidKind(string $kind): bool
    {
        return in_array($kind, self::VALID_KINDS, true);
    }

    public function toArray(): array
    {
        return [
            'id'                 => $this->id,
            'pack_id'            => $this->packId,
            'kind'               => $this->kind,
            'external_id_prefix' => $this->externalIdPrefix,
            'meta_json'          => $this->metaJson,
            'position'           => $this->position,
        ];
    }
}
