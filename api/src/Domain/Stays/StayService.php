<?php
declare(strict_types=1);

namespace App\Domain\Stays;

use App\Support\Errors\NotFoundException;
use App\Support\Errors\UnprocessableException;

/**
 * StayService: high-level operations around stays that are not state
 * transitions. Currently:
 *   - Reads (getOrFail, list).
 *   - Partial VB6 refs update.
 *
 * Transitions live in StayStateMachine. Creation from VB6 goes through
 * QrIssueService (TSK-061), which composes the state machine and this
 * service with validation.
 */
final class StayService
{
    private StayRepositoryInterface $repo;

    public function __construct(StayRepositoryInterface $repo)
    {
        $this->repo = $repo;
    }

    public function getOrFail(int $id): Stay
    {
        $stay = $this->repo->findById($id);
        if ($stay === null) {
            throw new NotFoundException('Stay not found', ['stay_id' => $id]);
        }
        return $stay;
    }

    /**
     * @param array<string,mixed> $filters
     * @return list<Stay>
     */
    public function listFiltered(array $filters, int $limit, int $offset): array
    {
        return $this->repo->listFiltered($filters, $limit, $offset);
    }

    /**
     * Merge/patch VB6 references. Only non-null values in $refs are updated.
     * Passing an explicit null for a key is treated as "leave unchanged" to
     * keep the semantics forgiving: VB6 typically sends a partial update.
     *
     * Returns the reloaded Stay.
     *
     * @param array<string,mixed> $refs
     */
    public function patchVb6Refs(int $stayId, array $refs): Stay
    {
        $stay = $this->getOrFail($stayId);

        $fields = [];
        $mapping = [
            'codalq' => 'vb6_codalq',
            'codtic' => 'vb6_codtic',
            'codcli' => 'vb6_codcli',
            'codart' => 'vb6_codart',
            'codlot' => 'vb6_codlot',
            'codhab' => 'vb6_codhab_raw',
            'temporada' => 'vb6_temporada',
            'empresa' => 'vb6_empresa',
            'departamento' => 'vb6_departamento',
        ];

        foreach ($mapping as $inputKey => $column) {
            if (!array_key_exists($inputKey, $refs)) {
                continue;
            }
            $v = $refs[$inputKey];
            if ($v === null) {
                // Accept null to clear a value; if you prefer "ignore null"
                // semantics, swap this branch for `continue;`.
                $fields[$column] = null;
                continue;
            }

            // Validate per-field.
            switch ($inputKey) {
                case 'codalq':
                case 'codtic':
                    $fields[$column] = $this->asIntOrFail($v, $inputKey, 1);
                    break;
                case 'codcli':
                case 'codart':
                case 'codlot':
                    $fields[$column] = $this->asIntOrFail($v, $inputKey, 1);
                    break;
                case 'codhab':
                    // P5: accept numeric or text. Persist the raw string.
                    if (is_int($v)) {
                        $fields[$column] = (string) $v;
                    } elseif (is_string($v) && $v !== '') {
                        if (mb_strlen($v) > 16) {
                            throw new UnprocessableException(
                                'invalid_vb6_ref',
                                'codhab too long (max 16)',
                                ['field' => 'codhab']
                            );
                        }
                        $fields[$column] = $v;
                    } else {
                        throw new UnprocessableException(
                            'invalid_vb6_ref',
                            'codhab must be a non-empty string or integer',
                            ['field' => 'codhab']
                        );
                    }
                    break;
                case 'temporada':
                    if (!is_string($v) || !preg_match('/^\d{4}$/', $v)) {
                        throw new UnprocessableException(
                            'invalid_vb6_ref',
                            'temporada must be a 4-digit year string',
                            ['field' => 'temporada']
                        );
                    }
                    $fields[$column] = $v;
                    break;
                case 'empresa':
                case 'departamento':
                    $n = $this->asIntOrFail($v, $inputKey, 0);
                    if ($n > 255) {
                        throw new UnprocessableException(
                            'invalid_vb6_ref',
                            "{$inputKey} must fit in TINYINT (0-255)",
                            ['field' => $inputKey]
                        );
                    }
                    $fields[$column] = $n;
                    break;
            }
        }

        if (!empty($fields)) {
            $this->repo->update($stayId, $fields);
        }
        $updated = $this->getOrFail($stayId);

        // Hook: if there are debts awaiting VB6 refs to be synced, reschedule
        // them immediately. Wired by DebtsService/outbox worker in TSK-111+
        // (F10). Left as a no-op stub so callers can depend on it today.
        $this->onVb6RefsUpdated($updated);

        return $updated;
    }

    /**
     * Hook invoked after a successful VB6 refs update. Default implementation
     * is a no-op; a future DebtsService will replace this by dispatching an
     * immediate resync attempt for PENDING_SYNC debts of the same stay.
     */
    protected function onVb6RefsUpdated(Stay $stay): void
    {
        // TODO(TSK-111+): reschedule PENDING_SYNC debts / outbox items.
    }

    /**
     * @param mixed $v
     */
    private function asIntOrFail($v, string $field, int $min = PHP_INT_MIN): int
    {
        if (is_int($v)) {
            $n = $v;
        } elseif (is_string($v) && preg_match('/^-?\d+$/', $v)) {
            $n = (int) $v;
        } else {
            throw new UnprocessableException(
                'invalid_vb6_ref',
                "{$field} must be an integer",
                ['field' => $field]
            );
        }
        if ($n < $min) {
            throw new UnprocessableException(
                'invalid_vb6_ref',
                "{$field} must be >= {$min}",
                ['field' => $field]
            );
        }
        return $n;
    }
}
