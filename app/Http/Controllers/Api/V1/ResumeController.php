<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreResumeRequest;
use App\Http\Resources\Api\V1\ResumeResource;
use App\Models\Resume;
use App\Services\ExtractResumeText;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ResumeController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Resume::class);

        $resumes = $request->user()->resumes()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(15);

        return ResumeResource::collection($resumes);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreResumeRequest $request, ExtractResumeText $extractResumeText): ResumeResource
    {
        Gate::authorize('create', Resume::class);

        $disk = config('filesystems.resume_disk');
        $file = $request->file('resume');

        if (! is_string($disk) || $disk === '' || $file === null) {
            throw new RuntimeException('Resume storage is not configured.');
        }

        $path = $file->store('users/'.$request->user()->getKey(), $disk);

        if ($path === false) {
            throw new RuntimeException('The resume could not be stored.');
        }

        try {
            $resume = $request->user()->resumes()->create([
                'disk' => $disk,
                'path' => $path,
                'original_name' => Str::limit($file->getClientOriginalName(), 255, ''),
                'mime_type' => $file->getMimeType() ?? 'application/pdf',
                'size' => (int) $file->getSize(),
            ]);
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($path);

            throw $exception;
        }

        return new ResumeResource($extractResumeText->handle($resume));
    }

    /**
     * Display the specified resource.
     */
    public function show(Resume $resume): ResumeResource
    {
        Gate::authorize('view', $resume);

        return new ResumeResource($resume);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Resume $resume): Response
    {
        Gate::authorize('delete', $resume);

        abort_if($resume->payments()->exists(), 409, 'A resume linked to a payment cannot be deleted.');

        Storage::disk($resume->disk)->delete($resume->path);
        $resume->delete();

        return response()->noContent();
    }
}
