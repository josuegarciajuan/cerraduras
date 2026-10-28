<?php
declare(strict_types=1);

namespace App\Domain\AccessKeys;

use App\Support\Errors\ConflictException;
use App\Support\Errors\NotFoundException;
use App\Support\Errors\UnprocessableException;

/** NfcCardService: lifecycle rules for physical NFC cards (F96). */
final class NfcCardService
{
    public function __construct(private NfcCardRepository $repo)
    {
    }

    /** @return list<array<string,mixed>> */
    public function list(array $filters = []): array
    {
        return $this->repo->findAll($filters);
    }

    public function get(int $id): array
    {
        $card = $this->repo->findById($id);
        if ($card === null) {
            throw new NotFoundException('NFC card not found', ['nfc_card_id' => $id]);
        }
        return $card;
    }

    public function create(array $data): array
    {
        $number = trim((string) ($data['number'] ?? ''));
        $code   = trim((string) ($data['code'] ?? ''));
        $notes  = isset($data['notes']) && trim((string) $data['notes']) !== ''
            ? trim((string) $data['notes']) : null;

        $this->validateNumber($number);
        if (mb_strlen($code) < 4 || mb_strlen($code) > 64) {
            throw new UnprocessableException('validation_error', 'code must be between 4 and 64 chars');
        }
        if ($this->repo->numberExists($number)) {
            throw new ConflictException('nfc_number_exists', 'NFC card number already exists', ['number' => $number]);
        }
        $hash = hash('sha256', $code);
        if ($this->repo->codeExists($hash)) {
            throw new ConflictException('nfc_code_exists', 'NFC card code already exists');
        }

        $id = $this->repo->insert($number, $hash, mb_substr($code, -4), $notes);
        return $this->get($id);
    }

    public function update(int $id, array $data): array
    {
        $card = $this->get($id);
        if ($card['status'] === 'ASSIGNED') {
            throw new ConflictException(
                'nfc_card_assigned',
                'Assigned cards cannot be edited; revoke the key first',
                ['nfc_card_id' => $id]
            );
        }

        $number = array_key_exists('number', $data) ? trim((string) $data['number']) : $card['number'];
        $notes  = array_key_exists('notes', $data)
            ? (trim((string) $data['notes']) !== '' ? trim((string) $data['notes']) : null)
            : $card['notes'];

        $this->validateNumber($number);
        if ($this->repo->numberExists($number, $id)) {
            throw new ConflictException('nfc_number_exists', 'NFC card number already exists', ['number' => $number]);
        }
        $this->repo->updateNumber($id, $number, $notes);
        return $this->get($id);
    }

    public function disable(int $id): array
    {
        $card = $this->get($id);
        if ($card['status'] === 'ASSIGNED') {
            throw new ConflictException(
                'nfc_card_assigned',
                'Assigned cards cannot be disabled; revoke the key first',
                ['nfc_card_id' => $id]
            );
        }
        $this->repo->setStatus($id, 'DISABLED');
        return $this->get($id);
    }

    private function validateNumber(string $number): void
    {
        if ($number === '') {
            throw new UnprocessableException('validation_error', 'number is required');
        }
        if (mb_strlen($number) > 32) {
            throw new UnprocessableException('validation_error', 'number must be <= 32 chars');
        }
    }
}
