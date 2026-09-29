<?php

declare(strict_types=1);

namespace App\Application\DTOs;

use App\Domain\ValueObjects\Email;
use App\Domain\ValueObjects\PhoneNumber;

final readonly class UpdateDoctorData
{
    private function __construct(
        public ?string $fullName = null,
        public ?string $specialty = null,
        public ?Email $email = null,
        public ?PhoneNumber $phone = null,
        public ?int $workingDayStartHour = null,
        public ?int $workingDayEndHour = null,
        public ?int $slotDurationMinutes = null,
        public ?string $status = null,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     */
    public static function fromArray(array $input): self
    {
        return new self(
            fullName: array_key_exists('full_name', $input) ? (string) $input['full_name'] : null,
            specialty: array_key_exists('specialty', $input) ? (string) $input['specialty'] : null,
            email: array_key_exists('email', $input) && ! empty($input['email'])
                ? Email::from((string) $input['email'])
                : null,
            phone: array_key_exists('phone', $input) && ! empty($input['phone'])
                ? PhoneNumber::from((string) $input['phone'])
                : null,
            workingDayStartHour: array_key_exists('working_day_start_hour', $input)
                ? (int) $input['working_day_start_hour']
                : null,
            workingDayEndHour: array_key_exists('working_day_end_hour', $input)
                ? (int) $input['working_day_end_hour']
                : null,
            slotDurationMinutes: array_key_exists('slot_duration_minutes', $input)
                ? (int) $input['slot_duration_minutes']
                : null,
            status: array_key_exists('status', $input) ? (string) $input['status'] : null,
        );
    }

    public function hasChanges(): bool
    {
        return $this->fullName !== null
            || $this->specialty !== null
            || $this->email !== null
            || $this->phone !== null
            || $this->workingDayStartHour !== null
            || $this->workingDayEndHour !== null
            || $this->slotDurationMinutes !== null
            || $this->status !== null;
    }
}
