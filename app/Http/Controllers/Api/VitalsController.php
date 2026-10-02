<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Encounter;
use App\Models\User;
use App\Models\Vital;
use App\Services\VitalsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VitalsController extends Controller
{
    public function __construct(private readonly VitalsService $vitals) {}

    public function index(Request $request, Encounter $encounter): JsonResponse
    {
        $vitals = $encounter->vitals()->with('recordedBy:id,name')->latest()->get();

        return response()->json([
            'success' => true,
            'data' => $vitals->map(fn (Vital $vital) => $this->present($vital))->values(),
        ]);
    }

    public function store(Request $request, Encounter $encounter): JsonResponse
    {
        $validated = $request->validate([
            'blood_pressure' => ['nullable', 'string', 'max:20'],
            'temperature' => ['nullable', 'numeric', 'between:30,45'],
            'heart_rate' => ['nullable', 'integer', 'between:20,300'],
            'weight' => ['nullable', 'numeric', 'between:1,400'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $vital = $this->vitals->record($encounter, $user, $validated);

        return response()->json([
            'success' => true,
            'data' => $this->present($vital),
        ], 201);
    }

    public function update(Request $request, Vital $vital): JsonResponse
    {
        $validated = $request->validate([
            'blood_pressure' => ['nullable', 'string', 'max:20'],
            'temperature' => ['nullable', 'numeric', 'between:30,45'],
            'heart_rate' => ['nullable', 'integer', 'between:20,300'],
            'weight' => ['nullable', 'numeric', 'between:1,400'],
        ]);

        $vital = $this->vitals->update($vital, request()->user(), $validated);

        return response()->json([
            'success' => true,
            'data' => $this->present($vital),
        ]);
    }

    public function destroy(Vital $vital): JsonResponse
    {
        $this->vitals->delete($vital, request()->user());

        return response()->json(['success' => true, 'message' => 'Vital sign deleted.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Vital $vital): array
    {
        return [
            'id' => $vital->id,
            'encounter_id' => $vital->encounter_id,
            'blood_pressure' => $vital->blood_pressure,
            'temperature' => $vital->temperature,
            'heart_rate' => $vital->heart_rate,
            'weight' => $vital->weight,
            'recorded_by' => $vital->recorded_by,
            'recorded_by_name' => $vital->recordedBy?->name,
            'recorded_at' => $vital->created_at?->toIso8601String(),
        ];
    }
}
