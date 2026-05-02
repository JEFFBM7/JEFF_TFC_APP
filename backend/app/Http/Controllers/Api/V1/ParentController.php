<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ParentRequest;
use App\Http\Resources\Api\V1\ParentResource;
use App\Models\ParentProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ParentController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ParentResource::collection(
            ParentProfile::query()
                ->with('user')
                ->withCount('students')
                ->orderBy('id')
                ->paginate(50),
        );
    }

    public function store(ParentRequest $request): JsonResponse
    {
        $parent = ParentProfile::query()->create($request->validated());

        return ParentResource::make($parent->load('user'))
            ->response()
            ->setStatusCode(201);
    }

    public function show(ParentProfile $parent): ParentResource
    {
        return ParentResource::make(
            $parent->load(['user', 'students.classroom.level']),
        );
    }

    public function update(ParentRequest $request, ParentProfile $parent): ParentResource
    {
        $parent->update($request->validated());

        return ParentResource::make($parent->fresh()->load('user'));
    }

    public function destroy(ParentProfile $parent): JsonResponse
    {
        $parent->delete();

        return response()->json(null, 204);
    }
}
