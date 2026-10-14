<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\AdminResumeResource;
use App\Models\Resume;
use App\ResumeExtractionStatus;
use App\Services\AdminAudit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ResumeController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'user_id' => ['nullable', 'integer'],
            'extraction_status' => ['nullable', Rule::enum(ResumeExtractionStatus::class)],
        ]);

        $resumes = Resume::query()
            ->with('user:id,name,email')
            ->when($validated['user_id'] ?? null, fn (Builder $query, int $userId) => $query->where('user_id', $userId))
            ->when($validated['extraction_status'] ?? null, fn (Builder $query, string $status) => $query->where('extraction_status', $status))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return AdminResumeResource::collection($resumes);
    }

    public function show(Resume $resume): AdminResumeResource
    {
        return new AdminResumeResource($resume->load('user:id,name,email'));
    }

    public function download(Resume $resume, AdminAudit $audit): StreamedResponse
    {
        $disk = Storage::disk($resume->disk);

        abort_unless($disk->exists($resume->path), 404, 'The resume file is no longer available.');

        $audit->record('resume.download', $resume, after: [
            'user_id' => $resume->user_id,
            'original_name' => $resume->original_name,
        ]);

        return $disk->download($resume->path, $resume->original_name, [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
