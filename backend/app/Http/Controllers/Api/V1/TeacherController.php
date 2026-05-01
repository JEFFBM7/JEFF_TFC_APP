<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AssignmentRequest;
use App\Http\Requests\Api\V1\TeacherRequest;
use App\Http\Resources\Api\V1\AssignmentResource;
use App\Http\Resources\Api\V1\TeacherResource;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TeacherController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return TeacherResource::collection(
            Teacher::query()->with('user')->orderBy('id')->paginate(50),
        );
    }

    public function store(TeacherRequest $request): JsonResponse
    {
        $teacher = Teacher::query()->create($request->validated());

        return TeacherResource::make($teacher->load('user'))->response()->setStatusCode(201);
    }

    public function show(Teacher $teacher): TeacherResource
    {
        return TeacherResource::make($teacher->load('user'));
    }

    public function update(TeacherRequest $request, Teacher $teacher): TeacherResource
    {
        $teacher->update($request->validated());

        return TeacherResource::make($teacher->fresh()->load('user'));
    }

    public function destroy(Teacher $teacher): JsonResponse
    {
        $teacher->delete();

        return response()->json(null, 204);
    }

    // ─── Assignments ─────────────────────────────────────────────────────────

    public function assignments(Request $request): AnonymousResourceCollection
    {
        $query = TeacherAssignment::query()
            ->with(['teacher.user', 'classroom.level', 'subject']);

        if ($request->filled('teacher_id')) {
            $query->where('teacher_id', $request->integer('teacher_id'));
        }
        if ($request->filled('classroom_id')) {
            $query->where('classroom_id', $request->integer('classroom_id'));
        }
        if ($request->filled('school_year_id')) {
            $query->where('school_year_id', $request->integer('school_year_id'));
        }

        return AssignmentResource::collection($query->paginate(100));
    }

    public function storeAssignment(AssignmentRequest $request): JsonResponse
    {
        $assignment = TeacherAssignment::query()->create($request->validated());

        return AssignmentResource::make(
            $assignment->load(['teacher.user', 'classroom.level', 'subject']),
        )->response()->setStatusCode(201);
    }

    public function destroyAssignment(TeacherAssignment $assignment): JsonResponse
    {
        $assignment->delete();

        return response()->json(null, 204);
    }
}
