<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Reclassification des 7e/8e année (anciennement primaire) vers le cycle CTEB
        // conformément à la nomenclature officielle EPST/RDC (edu-nc.gouv.cd/niveaux).
        foreach ([
            ['7e primaire', '7e CTEB', 'cteb', 20],
            ['8e primaire', '8e CTEB', 'cteb', 21],
        ] as [$legacyName, $newName, $cycle, $order]) {
            $existingTarget = DB::table('levels')->where('name', $newName)->first();
            $legacy = DB::table('levels')->where('name', $legacyName)->first();

            if ($legacy && ! $existingTarget) {
                DB::table('levels')->where('id', $legacy->id)->update([
                    'name' => $newName,
                    'cycle' => $cycle,
                    'order' => $order,
                ]);
            } elseif ($legacy && $existingTarget) {
                // Cible déjà présente : on rebascule les classes vers la cible et on supprime le legacy.
                DB::table('classrooms')->where('level_id', $legacy->id)->update(['level_id' => $existingTarget->id]);
                DB::table('levels')->where('id', $legacy->id)->delete();
                DB::table('levels')->where('id', $existingTarget->id)->update([
                    'cycle' => $cycle,
                    'order' => $order,
                ]);
            } elseif ($existingTarget) {
                DB::table('levels')->where('id', $existingTarget->id)->update([
                    'cycle' => $cycle,
                    'order' => $order,
                ]);
            }
        }

        // Ajout de la 4e secondaire (Humanités, 4 ans) si absente.
        if (! DB::table('levels')->where('name', '4e secondaire')->exists()) {
            DB::table('levels')->insert([
                'name' => '4e secondaire',
                'cycle' => 'secondaire',
                'order' => 33,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Retour arrière : 7e/8e CTEB redeviennent 7e/8e primaire.
        foreach ([
            ['7e CTEB', '7e primaire', 16],
            ['8e CTEB', '8e primaire', 17],
        ] as [$ctebName, $legacyName, $order]) {
            $cteb = DB::table('levels')->where('name', $ctebName)->first();
            if ($cteb) {
                DB::table('levels')->where('id', $cteb->id)->update([
                    'name' => $legacyName,
                    'cycle' => 'primaire',
                    'order' => $order,
                ]);
            }
        }

        // Suppression de la 4e secondaire si aucune classe n'y est rattachée.
        $fourth = DB::table('levels')->where('name', '4e secondaire')->first();
        if ($fourth && ! DB::table('classrooms')->where('level_id', $fourth->id)->exists()) {
            DB::table('levels')->where('id', $fourth->id)->delete();
        }
    }
};
