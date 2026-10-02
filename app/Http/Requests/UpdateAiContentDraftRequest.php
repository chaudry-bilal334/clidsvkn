<?php

namespace App\Http\Requests;

use App\Models\AiContentDraft;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateAiContentDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'content_type' => ['sometimes', 'required', 'string', Rule::in(AiContentDraft::CONTENT_TYPES)],
            'content' => ['sometimes', 'required', 'string', 'min:20'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $title = (string) ($this->input('title') ?? $this->route('aiContentDraft')->title);
            $content = (string) ($this->input('content') ?? $this->route('aiContentDraft')->content);

            if (AiContentDraft::containsClinicalMaterial($title, $content)) {
                $validator->errors()->add(
                    'content',
                    'AI content is restricted to non-clinical material; clinical guidance cannot be drafted here.'
                );
            }
        });
    }
}
