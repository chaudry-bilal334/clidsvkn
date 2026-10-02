<?php

namespace App\Http\Requests;

use App\Models\MedicalDocument;
use App\Rules\SniffedDocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class StoreMedicalDocumentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * The route middleware and controller enforce ownership and role-based
     * access; the form request only shapes the uploaded payload.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>|File|string>
     */
    public function rules(): array
    {
        return [
            'category' => ['required', 'string', Rule::in(MedicalDocument::CATEGORIES)],
            'file' => [
                'required',
                File::types(['pdf', 'jpeg', 'jpg', 'png', 'webp'])
                    ->max(5 * 1024),
                new SniffedDocumentType,
            ],
        ];
    }
}
