<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PromotionCommitRequest;
use App\Models\PromotionBatch;
use App\Models\SchoolYear;
use App\Services\PromotionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PromotionController extends Controller
{
    public function __construct(private readonly PromotionService $promotion)
    {
    }

    /** Aperçu (dry-run) du passage de l'année source vers l'année cible. */
    public function preview(Request $request, SchoolYear $schoolYear): JsonResponse
    {
        $to = SchoolYear::query()->findOrFail($request->integer('to_year_id'));

        if ($to->id === $schoolYear->id) {
            return response()->json(['message' => "L'année cible doit être différente de l'année source."], 422);
        }

        return response()->json(['data' => $this->promotion->preview($schoolYear, $to)]);
    }

    /** Applique le passage : crée les inscriptions de l'année cible. */
    public function commit(PromotionCommitRequest $request, SchoolYear $schoolYear): JsonResponse
    {
        $to = SchoolYear::query()->findOrFail($request->integer('to_year_id'));

        if ($to->id === $schoolYear->id) {
            return response()->json(['message' => "L'année cible doit être différente de l'année source."], 422);
        }

        if ($to->isArchived()) {
            return response()->json(['message' => 'Année cible archivée : impossible d’y inscrire des élèves.'], 423);
        }

        if ($to->is_current) {
            return response()->json([
                'message' => "L'année cible est déjà l'année courante : effectuez le passage avant de la rendre courante.",
            ], 422);
        }

        $batch = $this->promotion->commit(
            $schoolYear,
            $to,
            $request->validated()['decisions'],
            $request->user()?->id,
        );

        return response()->json([
            'data' => [
                'id' => $batch->id,
                'from_school_year_id' => $batch->from_school_year_id,
                'to_school_year_id' => $batch->to_school_year_id,
                'promoted_count' => $batch->promoted_count,
                'repeated_count' => $batch->repeated_count,
                'graduated_count' => $batch->graduated_count,
                'status' => $batch->status,
            ],
        ], 201);
    }

    /** Annule un lot de passage tant que l'année cible n'est pas devenue courante. */
    public function rollback(PromotionBatch $promotionBatch): JsonResponse
    {
        if ($promotionBatch->status === PromotionBatch::STATUS_ROLLED_BACK) {
            return response()->json(['message' => 'Ce passage a déjà été annulé.'], 422);
        }

        $to = $promotionBatch->toSchoolYear;

        if ($to !== null && $to->is_current) {
            return response()->json([
                'message' => "Impossible d'annuler : l'année cible est déjà l'année courante.",
            ], 409);
        }

        if ($to !== null && $to->isArchived()) {
            return response()->json(['message' => 'Année cible archivée : annulation impossible.'], 423);
        }

        $this->promotion->rollback($promotionBatch);

        return response()->json(['data' => ['id' => $promotionBatch->id, 'status' => $promotionBatch->fresh()->status]]);
    }
}
