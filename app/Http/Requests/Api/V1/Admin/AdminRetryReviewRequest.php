<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AdminRetryReviewRequest extends FormRequest
{
    /**
     * Admin access is enforced by the EnsureAdmin middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'resume_id' => ['sometimes', 'integer', 'exists:resumes,id'],
        ];
    }
}
