<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\AdminPaymentResource;
use App\Http\Resources\Api\V1\Admin\AdminResumeResource;
use App\Http\Resources\Api\V1\Admin\AdminReviewResource;
use App\Http\Resources\Api\V1\Admin\AdminUserResource;
use App\Models\User;
use App\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::enum(UserRole::class)],
        ]);

        $search = isset($validated['search']) ? trim($validated['search']) : '';

        $users = User::query()
            ->withCount(['resumes', 'payments', 'reviews'])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $term = '%'.addcslashes(mb_strtolower($search), '%_\\').'%';
                $query->where(fn (Builder $query) => $query
                    ->whereRaw("lower(name) like ? escape '\\'", [$term])
                    ->orWhereRaw("lower(email) like ? escape '\\'", [$term]));
            })
            ->when($validated['role'] ?? null, fn (Builder $query, string $role) => $query->where('role', $role))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return AdminUserResource::collection($users);
    }

    public function show(User $user): JsonResponse
    {
        $user->loadCount(['resumes', 'payments', 'reviews']);

        $latest = fn (string $relation) => $user->{$relation}()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(5);

        return response()->json([
            'data' => [
                ...(new AdminUserResource($user))->resolve(request()),
                'latest_resumes' => AdminResumeResource::collection($latest('resumes')->get()),
                'latest_payments' => AdminPaymentResource::collection($latest('payments')->with('review:id,payment_id')->get()),
                'latest_reviews' => AdminReviewResource::collection(
                    $latest('reviews')->with(['resume:id,original_name', 'payment:id,status'])->get()
                ),
            ],
        ]);
    }
}
