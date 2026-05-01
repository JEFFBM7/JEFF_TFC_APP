<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LevelRequest;
use App\Http\Resources\Api\V1\LevelResource;
use App\Models\Level;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LevelController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return LevelResource::collection(
            Level::query()->with('classrooms')->orderBy('order')->orderBy('name')->paginate(50),
        );
    }

    public function store(LevelRequest $request): JsonResponse
    {
        $level = Level::query()->create($request->validated());

        return LevelResource::make($level)->response()->setStatusCode(201);
    }

    public function show(Level $level): LevelResource
    {
        return LevelResource::make($level->load('classrooms'));
    }

    public function update(LevelRequest $request, Level $level): LevelResource
    {
        $level->update($request->validated());

        return LevelResource::make($level->fresh()->load('classrooms'));
    }

    public function destroy(Level $level): JsonResponse
    {
        if ($level->classrooms()->exists()) {
            return response()->json([
                'message' => 'Impossible de supprimer un niveau contenant des classes.',
            ], 422);
        }

        $level->delete();

        return response()->json(null, 204);
    }
}
