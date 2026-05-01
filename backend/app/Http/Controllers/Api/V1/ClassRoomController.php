<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ClassRoomRequest;
use App\Http\Resources\Api\V1\ClassRoomResource;
use App\Models\ClassRoom;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ClassRoomController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = ClassRoom::query()->with('level');

        if ($request->filled('level_id')) {
            $query->where('level_id', $request->integer('level_id'));
        }

        return ClassRoomResource::collection(
            $query->orderBy('level_id')->orderBy('section')->paginate(100),
        );
    }

    public function store(ClassRoomRequest $request): JsonResponse
    {
        $classroom = ClassRoom::query()->create($request->validated());

        return ClassRoomResource::make($classroom->load('level'))->response()->setStatusCode(201);
    }

    public function show(ClassRoom $classroom): ClassRoomResource
    {
        return ClassRoomResource::make($classroom->load('level'));
    }

    public function update(ClassRoomRequest $request, ClassRoom $classroom): ClassRoomResource
    {
        $classroom->update($request->validated());

        return ClassRoomResource::make($classroom->fresh()->load('level'));
    }

    public function destroy(ClassRoom $classroom): JsonResponse
    {
        $classroom->delete();

        return response()->json(null, 204);
    }
}
