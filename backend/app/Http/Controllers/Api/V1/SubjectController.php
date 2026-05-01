<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ClassroomSubjectRequest;
use App\Http\Requests\Api\V1\SubjectRequest;
use App\Http\Resources\Api\V1\SubjectResource;
use App\Models\ClassRoom;
use App\Models\Subject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SubjectController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return SubjectResource::collection(Subject::query()->orderBy('name')->paginate(100));
    }

    public function store(SubjectRequest $request): JsonResponse
    {
        $subject = Subject::query()->create($request->validated());

        return SubjectResource::make($subject)->response()->setStatusCode(201);
    }

    public function show(Subject $subject): SubjectResource
    {
        return SubjectResource::make($subject);
    }

    public function update(SubjectRequest $request, Subject $subject): SubjectResource
    {
        $subject->update($request->validated());

        return SubjectResource::make($subject->fresh());
    }

    public function destroy(Subject $subject): JsonResponse
    {
        $subject->delete();

        return response()->json(null, 204);
    }

    // ─── Coefficient per classroom ───────────────────────────────────────────

    /** List subjects attached to a classroom with their coefficients. */
    public function classroomSubjects(ClassRoom $classroom): AnonymousResourceCollection
    {
        return SubjectResource::collection(
            $classroom->subjects()->orderBy('name')->get(),
        );
    }

    /** Attach or update a subject + coefficient on a classroom. */
    public function syncClassroomSubject(ClassroomSubjectRequest $request, ClassRoom $classroom): JsonResponse
    {
        $data = $request->validated();
        $classroom->subjects()->syncWithoutDetaching([
            $data['subject_id'] => ['coefficient' => $data['coefficient'] ?? 1.0],
        ]);

        $subjects = $classroom->subjects()->orderBy('name')->get();

        return SubjectResource::collection($subjects)->response()->setStatusCode(200);
    }

    /** Remove a subject from a classroom. */
    public function detachClassroomSubject(ClassRoom $classroom, Subject $subject): JsonResponse
    {
        $classroom->subjects()->detach($subject->id);

        return response()->json(null, 204);
    }
}
