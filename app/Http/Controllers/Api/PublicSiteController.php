<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Communication;
use App\Models\DoctorSchedule;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Public, unauthenticated surface of the clinic website (PRD Module 1).
 *
 * These endpoints expose only approved marketing and clinic-identity content
 * plus the ability to raise a booking/contact request as a new lead. They
 * never expose patient, clinical, financial or staff records beyond the
 * doctor directory, which is gated by the approved doctor schedule data.
 */
class PublicSiteController extends Controller
{
    public function info(): JsonResponse
    {
        $branch = $this->primaryBranch();

        return response()->json([
            'success' => true,
            'data' => [
                'name' => $branch?->name,
                'city' => $branch?->city,
                'address' => $branch?->address,
                'phone' => $branch?->phone,
                'services' => config('clinic.services', []),
            ],
        ]);
    }

    public function services(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'services' => config('clinic.services', []),
            ],
        ]);
    }

    public function doctors(): JsonResponse
    {
        $doctors = User::query()
            ->where('role', 'Doctor')
            ->with([
                'doctorSchedules' => fn ($query) => $query->where('is_active', true)->orderBy('day_of_week')->orderBy('start_time'),
            ])
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $doctors->map(fn (User $doctor) => [
                'id' => $doctor->id,
                'name' => $doctor->name,
                'schedules' => $doctor->doctorSchedules->map(
                    fn (DoctorSchedule $schedule) => [
                        'day_of_week' => $schedule->day_of_week,
                        'start_time' => $schedule->start_time,
                        'end_time' => $schedule->end_time,
                    ],
                )->values(),
            ])->values(),
        ]);
    }

    /**
     * Accept a public booking request and raise it as a new website lead.
     */
    public function booking(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['required', 'string', 'max:40'],
            'service_interest' => ['nullable', 'string', 'max:150'],
            'preferred_doctor_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('role', 'Doctor')],
            'preferred_date' => ['nullable', 'date'],
            'preferred_time' => ['nullable', 'date_format:H:i'],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        $lead = $this->createWebsiteLead($validated);

        return response()->json([
            'success' => true,
            'message' => 'Your booking request has been received. Our team will contact you to confirm the appointment.',
            'data' => [
                'lead_id' => $lead->id,
                'status' => $lead->status,
            ],
        ], 201);
    }

    /**
     * Accept a public contact inquiry and raise it as a new website lead.
     */
    public function contact(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:40'],
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $lead = $this->createWebsiteLead($validated);

        return response()->json([
            'success' => true,
            'message' => 'Thank you for contacting us. We will get back to you shortly.',
            'data' => [
                'lead_id' => $lead->id,
                'status' => $lead->status,
            ],
        ], 201);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function createWebsiteLead(array $validated): Lead
    {
        $lead = Lead::create([
            'branch_id' => $this->primaryBranch()?->id,
            'name' => $validated['name'],
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'service_interest' => $validated['service_interest'] ?? null,
            'preferred_doctor_id' => isset($validated['preferred_doctor_id']) ? (int) $validated['preferred_doctor_id'] : null,
            'preferred_date' => $validated['preferred_date'] ?? null,
            'preferred_time' => $validated['preferred_time'] ?? null,
            'source' => Lead::SOURCE_WEBSITE,
            'status' => Lead::STATUS_NEW,
            'notes' => $validated['message'] ?? null,
        ]);

        Communication::create([
            'lead_id' => $lead->id,
            'user_id' => null,
            'channel' => Communication::CHANNEL_WEBSITE,
            'summary' => 'Website inquiry received',
            'notes' => $validated['message'] ?? null,
            'occurred_at' => now(),
        ]);

        return $lead;
    }

    private function primaryBranch(): ?Branch
    {
        return Branch::query()->where('is_active', true)->orderBy('id')->first();
    }
}
