<?php

namespace App\Http\Requests;

use App\Models\SystemSetting;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSystemSettingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $setting = $this->route('systemSetting');

        return $setting instanceof SystemSetting
            && ($this->user()?->can('manage', $setting) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $setting = $this->route('systemSetting');

        return [
            'key' => [
                'sometimes',
                'string',
                'max:100',
                Rule::unique('system_settings', 'key')->ignore($setting?->id),
            ],
            'value' => ['sometimes', 'nullable'],
            'type' => ['sometimes', 'string', Rule::in(['string', 'boolean', 'json', 'integer', 'float'])],
        ];
    }
}
