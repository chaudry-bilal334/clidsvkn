<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DoctorSchedule;
use App\Models\DoctorTimeOff;
use App\Models\User;
use App\Services\BranchContext;
use App\Services\DoctorAvailabilityService;
use App\Services\QueryCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DoctorAvailabilityController extends Controller
{
    public function __construct(
        private readonly DoctorAvailabilityService $availability,
        private readonly QueryCacheService $queryCache,
    ) {}

    public function doctors(): JsonResponse
    {
        $doctors = User::query()
            ->where('role', 'Doctor')
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json(['success' => true, 'data' => $doctors]);
    }

    public function availability(Request $request, User $doctor): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        $branchKey = BranchContext::id() === null ? 'all' : 'b'.BranchContext::id();

        $data = $this->queryCache->rememberGroup(
            'appointments.availability',
            $branchKey.':'.$doctor->id.':'.$validated['date'],
            fn (): array => $this->availability->slotsForDate($doctor, $validated['date']),
            600,
        );

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function schedules(User $doctor): JsonResponse
    {
        $schedules = $doctor->doctorSchedules()
            ->orderBy('day_of_week')
            ->orderBy('start_time')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $schedules->map(fn (DoctorSchedule $schedule) => [
                'id' => $schedule->id,
                'day_of_week' => $schedule->day_of_week,
                'start_time' => $schedule->start_time,
                'end_time' => $schedule->end_time,
                'is_active' => $schedule->is_active,
            ])->values(),
        ]);
    }

    public function storeSchedule(Request $request, User $doctor): JsonResponse
    {
        $schedule = $this->availability->createSchedule(
            $this->validateSchedule($request) + ['doctor_id' => $doctor->id],
        );

        return response()->json([
            'success' => true,
            'data' => $this->schedulePayload($schedule),
        ], 201);
    }

    public function updateSchedule(Request $request, DoctorSchedule $schedule): JsonResponse
    {
        $schedule = $this->availability->updateSchedule($schedule, $this->validateSchedule($request));

        return response()->json([
            'success' => true,
            'data' => $this->schedulePayload($schedule->fresh()),
        ]);
    }

    public function destroySchedule(DoctorSchedule $schedule): JsonResponse
    {
        $schedule->delete();

        return response()->json(['success' => true]);
    }

    public function timeOffs(User $doctor): JsonResponse
    {
        $timeOffs = $doctor->doctorTimeOffs()
            ->orderBy('start_date')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $timeOffs->map(fn (DoctorTimeOff $timeOff) => [
                'id' => $timeOff->id,
                'start_date' => $timeOff->start_date->toDateString(),
                'end_date' => $timeOff->end_date->toDateString(),
                'reason' => $timeOff->reason,
            ])->values(),
        ]);
    }

    public function storeTimeOff(Request $request, User $doctor): JsonResponse
    {
        $validated = $this->validateTimeOff($request);

        if ($validated['end_date'] < $validated['start_date']) {
            abort(422, 'End date cannot be before the start date.');
        }

        $timeOff = $doctor->doctorTimeOffs()->create($validated);

        return response()->json([
            'success' => true,
            'data' => $this->timeOffPayload($timeOff),
        ], 201);
    }

    public function updateTimeOff(Request $request, DoctorTimeOff $timeOff): JsonResponse
    {
        $validated = $this->validateTimeOff($request);

        if ($validated['end_date'] < $validated['start_date']) {
            abort(422, 'End date cannot be before the start date.');
        }

        $timeOff->update($validated);

        return response()->json([
            'success' => true,
            'data' => $this->timeOffPayload($timeOff->refresh()),
        ]);
    }

    public function destroyTimeOff(DoctorTimeOff $timeOff): JsonResponse
    {
        $timeOff->delete();

        return response()->json(['success' => true]);
    }

    /**
     * @return array{day_of_week: int, start_time: string, end_time: string, is_active: bool}
     */
    private function validateSchedule(Request $request): array
    {
        $validated = $request->validate([
            'day_of_week' => ['required', 'integer', 'between:0,6'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        return [
            'day_of_week' => (int) $validated['day_of_week'],
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'],
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ];
    }

    /**
     * @return array{start_date: string, end_date: string, reason: string|null}
     */
    private function validateTimeOff(Request $request): array
    {
        $validated = $request->validate([
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        return [
            'start_date' => $validated['start_date'],
            'end_date' => $validated['end_date'],
            'reason' => $validated['reason'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function schedulePayload(DoctorSchedule $schedule): array
    {
        return [
            'id' => $schedule->id,
            'doctor_id' => $schedule->doctor_id,
            'day_of_week' => $schedule->day_of_week,
            'start_time' => $schedule->start_time,
            'end_time' => $schedule->end_time,
            'is_active' => $schedule->is_active,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function timeOffPayload(DoctorTimeOff $timeOff): array
    {
        return [
            'id' => $timeOff->id,
            'doctor_id' => $timeOff->doctor_id,
            'start_date' => $timeOff->start_date->toDateString(),
            'end_date' => $timeOff->end_date->toDateString(),
            'reason' => $timeOff->reason,
        ];
    }
}
