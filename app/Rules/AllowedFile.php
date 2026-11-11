<?php

namespace App\Rules;

use App\Models\FileType;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * The upload's extension must be in System > File types, and the file must fit that type's size limit.
 * Checks the file name's extension, not the MIME type: formats like .zprj or .emb have no MIME type PHP knows.
 * Every part of the name is checked against FileType::blocked(), so "shell.php.zip" is refused too.
 */
class AllowedFile implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value instanceof UploadedFile && $problem = FileType::problem($value->getClientOriginalName(), (int) $value->getSize())) {
            $fail($problem);
        }
    }
}
