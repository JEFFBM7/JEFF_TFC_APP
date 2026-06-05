<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SchoolOptionRequest;
use App\Http\Resources\Api\V1\SchoolOptionResource;
use App\Models\SchoolOption;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SchoolOptionController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return SchoolOptionResource::collection(
            SchoolOption::query()->orderBy('name')->paginate(100),
        );
    }

    public function store(SchoolOptionRequest $request): JsonResponse
    {
        $option = SchoolOption::query()->create($request->validated());

        return SchoolOptionResource::make($option)->response()->setStatusCode(201);
    }
}
