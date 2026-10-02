<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSystemSettingRequest;
use App\Http\Requests\UpdateSystemSettingRequest;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;

class SystemSettingsController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', SystemSetting::class);

        $settings = SystemSetting::orderBy('key')->get()->map(fn (SystemSetting $s) => $this->present($s));

        return response()->json([
            'success' => true,
            'data' => $settings,
        ]);
    }

    public function store(StoreSystemSettingRequest $request): JsonResponse
    {
        $this->authorize('create', SystemSetting::class);

        $setting = SystemSetting::create($request->validated());

        return response()->json([
            'success' => true,
            'data' => $this->present($setting),
        ], 201);
    }

    public function show(SystemSetting $systemSetting): JsonResponse
    {
        $this->authorize('view', $systemSetting);

        return response()->json([
            'success' => true,
            'data' => $this->present($systemSetting),
        ]);
    }

    public function update(UpdateSystemSettingRequest $request, SystemSetting $systemSetting): JsonResponse
    {
        $this->authorize('update', $systemSetting);

        $systemSetting->update($request->validated());

        return response()->json([
            'success' => true,
            'data' => $this->present($systemSetting->refresh()),
        ]);
    }

    public function destroy(SystemSetting $systemSetting): JsonResponse
    {
        $this->authorize('delete', $systemSetting);

        $systemSetting->delete();

        return response()->json([
            'success' => true,
            'message' => 'System setting deleted.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(SystemSetting $setting): array
    {
        return [
            'id' => $setting->id,
            'key' => $setting->key,
            'value' => $setting->value,
            'type' => $setting->type,
            'created_at' => $setting->created_at?->toIso8601String(),
            'updated_at' => $setting->updated_at?->toIso8601String(),
        ];
    }
}
