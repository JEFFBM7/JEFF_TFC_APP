<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\TimetableSlotRequest;
use App\Http\Resources\Api\V1\TimetableSlotResource;
use App\Models\Teacher;
use App\Models\TimetableSlot;
use App\Support\AdminScopeContext;
use App\Support\SchoolYearContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TimetableSlotController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = TimetableSlot::query()->with(['classroom.level', 'subject', 'teacher.user']);

        if ($request->user()?->role === UserRole::Enseignant) {
            $teacherId = Teacher::query()
                ->where('user_id', $request->user()->id)
                ->value('id');

            $query->where('teacher_id', $teacherId ?? 0);
        }

        foreach (['classroom_id', 'subject_id', 'teacher_id', 'day_of_week'] as $key) {
            if ($request->filled($key)) {
                if ($key === 'classroom_id') {
                    AdminScopeContext::assertClassroomAllowed($request->user(), $request->integer($key));
                }
                $query->where($key, $request->integer($key));
            }
        }

        SchoolYearContext::applySchoolYearColumn($query, $request);

        if ($request->filled('cycle')) {
            if (! AdminScopeContext::requestedCycleIsAllowed($request)) {
                $query->whereRaw('1 = 0');
            }
            $query->whereHas('classroom.level', function ($levelQuery) use ($request): void {
                $levelQuery->where('cycle', $request->string('cycle')->value());
            });
        }
        if ($request->user()?->hasRole('admin') && ! AdminScopeContext::isGlobalAdmin($request->user())) {
            $query->whereHas('classroom.level', fn ($levelQuery) => $levelQuery
                ->whereIn('cycle', AdminScopeContext::allowedCycles($request->user())));
        }

        return TimetableSlotResource::collection(
            $query->orderBy('day_of_week')->orderBy('starts_at')->paginate(200),
        );
    }

    public function store(TimetableSlotRequest $request): JsonResponse
    {
        SchoolYearContext::assertNotArchivedById($request->integer('school_year_id'));
        AdminScopeContext::assertClassroomAllowed($request->user(), $request->integer('classroom_id'));

        $slot = TimetableSlot::query()->create($request->validated());

        return TimetableSlotResource::make($slot->load(['classroom.level', 'subject', 'teacher.user']))
            ->response()
            ->setStatusCode(201);
    }

    public function show(TimetableSlot $timetableSlot): TimetableSlotResource
    {
        AdminScopeContext::assertClassroomAllowed(request()->user(), $timetableSlot->classroom_id);

        return TimetableSlotResource::make(
            $timetableSlot->load(['classroom.level', 'subject', 'teacher.user']),
        );
    }

    public function update(TimetableSlotRequest $request, TimetableSlot $timetableSlot): TimetableSlotResource
    {
        SchoolYearContext::assertNotArchivedById($timetableSlot->school_year_id);
        SchoolYearContext::assertNotArchivedById($request->integer('school_year_id'));
        AdminScopeContext::assertClassroomAllowed($request->user(), $timetableSlot->classroom_id);
        AdminScopeContext::assertClassroomAllowed($request->user(), $request->integer('classroom_id'));

        $timetableSlot->update($request->validated());

        return TimetableSlotResource::make(
            $timetableSlot->fresh()->load(['classroom.level', 'subject', 'teacher.user']),
        );
    }

    public function destroy(TimetableSlot $timetableSlot): JsonResponse
    {
        SchoolYearContext::assertNotArchivedById($timetableSlot->school_year_id);
        AdminScopeContext::assertClassroomAllowed(request()->user(), $timetableSlot->classroom_id);

        $timetableSlot->delete();

        return response()->json(null, 204);
    }
}
