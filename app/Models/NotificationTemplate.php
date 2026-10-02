<?php

namespace App\Models;

use Database\Factories\NotificationTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['channel', 'name', 'subject', 'body', 'is_approved', 'approved_by', 'approved_at', 'created_by'])]
class NotificationTemplate extends Model
{
    /** @use HasFactory<NotificationTemplateFactory> */
    use HasFactory, SoftDeletes;

    /**
     * The model's default attribute values. Mirrors the column default so a
     * freshly created template reports is_approved = false in memory instead
     * of null (the database-level default alone leaves the attribute unset).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_approved' => false,
    ];

    public const CHANNEL_EMAIL = 'email';

    public const CHANNEL_SMS = 'sms';

    public const CHANNEL_WHATSAPP = 'whatsapp';

    /**
     * @var array<string, string>
     */
    public const CHANNELS = [
        self::CHANNEL_EMAIL,
        self::CHANNEL_SMS,
        self::CHANNEL_WHATSAPP,
    ];

    /**
     * Sample values used by the preview endpoint so staff can see exactly how
     * a template renders without sending anything.
     *
     * @var array<string, string>
     */
    public const PREVIEW_DATA = [
        'patient_name' => 'Ahmed Khan',
        'appointment_date' => '2026-09-30',
        'appointment_time' => '10:30 AM',
        'doctor_name' => 'Dr. Fatima Noor',
        'clinic_name' => 'Al-Shifa Clinic',
        'invoice_number' => 'INV-000123',
        'amount' => '1,200 PKR',
        'test_name' => 'Complete Blood Count',
        'followup_date' => '2026-10-15',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_approved' => 'boolean',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Render the template body, replacing {{placeholder}} tokens with the
     * supplied values. Unknown placeholders are left untouched so a partial
     * payload never silently drops text.
     */
    public function renderBody(array $data = []): string
    {
        return strtr((string) $this->body, $this->tokenMap($data));
    }

    /**
     * Render the email subject the same way; SMS/WhatsApp have no subject.
     */
    public function renderSubject(array $data = []): ?string
    {
        $subject = $this->subject;

        if (! is_string($subject) || $subject === '') {
            return null;
        }

        return strtr($subject, $this->tokenMap($data));
    }

    /**
     * @return array<string, string>
     */
    private function tokenMap(array $data): array
    {
        $map = [];

        foreach ($data as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $map['{{'.$key.'}}'] = (string) $value;
            }
        }

        return $map;
    }
}
