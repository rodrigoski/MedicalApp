<?php

declare(strict_types=1);

namespace App\Application\DTOs;

use App\Domain\ValueObjects\Email;
use App\Domain\ValueObjects\LicenseNumber;
use App\Domain\ValueObjects\PhoneNumber;

final readonly class CreateDoctorData
{
    public function __construct(
        public string $fullName,
        public LicenseNumber $licenseNumber,
        public string $specialty,
        public ?Email $email = null,
        public ?PhoneNumber $phone = null,
        public int $workingDayStartHour = 8,
        public int $workingDayEndHour = 18,
        public int $slotDurationMinutes = 30,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     */
    public static function fromArray(array $input): self
    {
        return new self(
            fullName: (string) ($input['full_name'] ?? ''),
            licenseNumber: LicenseNumber::from((string) ($input['license_number'] ?? '')),
            specialty: (string) ($input['specialty'] ?? ''),
            email: Email::tryFrom(isset($input['email']) ? (string) $input['email'] : null),
            phone: PhoneNumber::tryFrom(isset($input['phone']) ? (string) $input['phone'] : null),
            workingDayStartHour: (int) ($input['working_day_start_hour'] ?? 8),
            workingDayEndHour: (int) ($input['working_day_end_hour'] ?? 18),
            slotDurationMinutes: (int) ($input['slot_duration_minutes'] ?? 30),
        );
    }
}
