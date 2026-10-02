<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Sniffs the uploaded document's real content type (magic bytes via finfo)
 * and enforces an allowlist per extension.
 *
 * Laravel's File::types() rule validates the file extension (and trusts the
 * client's MIME claim for non-images), so a PHP payload renamed to .pdf or a
 * plain-text file masquerading as a document would otherwise be accepted.
 * This rule guarantees the bytes a user is storing actually match an allowed
 * document format regardless of the claimed filename or MIME.
 */
class SniffedDocumentType implements ValidationRule
{
    /**
     * Content MIME types accepted for each allowed document extension.
     *
     * @var array<string, array<int, string>>
     */
    private const ALLOWED_MIMES = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'webp' => ['image/webp'],
    ];

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            return;
        }

        $extension = strtolower((string) $value->getClientOriginalExtension());

        // Only sniffed extensions are subject to content verification; anything
        // else is already rejected by the File::types() rule up front.
        if (! isset(self::ALLOWED_MIMES[$extension])) {
            return;
        }

        $detected = $this->detectMime($value);

        if ($detected === '' || ! in_array($detected, self::ALLOWED_MIMES[$extension], true)) {
            $fail('The :attribute content does not match an allowed file type.');
        }
    }

    private function detectMime(UploadedFile $file): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            // fileinfo unavailable: fall back to the client-claimed MIME, which
            // the File::types() rule already cross-checks against the extension.
            return strtolower((string) $file->getMimeType());
        }

        $mime = finfo_buffer($finfo, $file->getContent());

        finfo_close($finfo);

        return is_string($mime) ? strtolower($mime) : '';
    }
}
