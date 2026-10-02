<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\MedicalDocument;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentService
{
    /**
     * Upload a medical document for a patient.
     */
    public function upload(Patient $patient, User $actor, UploadedFile $file, string $category, ?string $notes = null): MedicalDocument
    {
        return DB::transaction(function () use ($patient, $actor, $file, $category): MedicalDocument {
            $extension = $file->getClientOriginalExtension();
            $safeName = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME))
                .'_'.now()->format('His').'.'.$extension;

            $path = $file->storeAs('', $safeName, 'medical');

            $document = MedicalDocument::create([
                'patient_id' => $patient->id,
                'uploaded_by' => $actor->id,
                'category' => $category,
                'original_name' => $file->getClientOriginalName(),
                'file_path' => $path,
                'mime_type' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
            ]);

            AuditLog::record(
                $actor,
                'medical_document.created',
                $document,
                newValues: [
                    'patient_id' => $patient->id,
                    'category' => $category,
                    'original_name' => $document->original_name,
                    'mime_type' => $document->mime_type,
                    'size_bytes' => $document->size_bytes,
                ],
            );

            return $document;
        });
    }

    /**
     * Delete a medical document (soft delete).
     *
     * The row is retired first so a failure can never leave a committed
     * record pointing at a file that has already been removed.
     */
    public function delete(MedicalDocument $document, User $actor): bool
    {
        $filePath = $document->file_path;

        DB::transaction(function () use ($document, $actor, $filePath): void {
            $document->delete();

            AuditLog::record(
                $actor,
                'medical_document.deleted',
                $document,
                oldValues: ['file_path' => $filePath],
                newValues: ['deleted_at' => $document->deleted_at?->toIso8601String()],
            );
        });

        $disk = Storage::disk('medical');

        if ($disk->exists($filePath)) {
            $disk->delete($filePath);
        }

        return true;
    }

    /**
     * Update document metadata (category, notes).
     */
    public function update(MedicalDocument $document, User $actor, array $fields): MedicalDocument
    {
        $old = $document->only(['category', 'notes']);

        $document->fill($fields);
        $document->save();

        AuditLog::record(
            $actor,
            'medical_document.updated',
            $document,
            oldValues: $old,
            newValues: $document->only(['category', 'notes']),
        );

        return $document->fresh();
    }
}
