<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Corrige (et prévient) les divisions « orphelines ».
     *
     * `classrooms.school_class_id` était en nullOnDelete : à la suppression d'une
     * SchoolClass (directement, ou en cascade depuis la SchoolYear), la division
     * survivait avec school_class_id = NULL. Le contrôleur des classes affiche
     * volontairement les divisions sans SchoolClass (« classes globales »), si
     * bien que ces orphelines réapparaissaient dans TOUTES les listes déroulantes
     * de classe → doublons visibles côté UI.
     *
     * Le hook SchoolYear::deleting purgeait déjà les divisions, mais uniquement
     * sur le chemin Eloquent ($model->delete()). Une suppression via query builder
     * ou SQL brut le contourne et laisse des orphelines. Passer la FK en cascade
     * déplace la garantie au niveau BASE DE DONNÉES : elle couvre alors tous les
     * chemins de suppression. L'effet sur les données est identique à celui du
     * hook actuel (les dépendances de la division — cours, profs, emploi du temps,
     * évaluations — sont déjà en cascade ; élèves/inscriptions/présences en
     * nullOnDelete, la source de vérité restant la table enrollments).
     *
     * Idempotente : purge conditionnelle + FK re-déclarée.
     */
    public function up(): void
    {
        // 1. Purge des orphelines SANS aucune donnée rattachée. On reste
        //    conservateur : une orpheline qui porterait encore des élèves ou des
        //    évaluations relève d'un incident à examiner à la main, pas d'une
        //    purge automatique.
        $emptyOrphans = DB::table('classrooms')
            ->whereNull('school_class_id')
            ->where(function ($query): void {
                foreach (['students', 'evaluations', 'timetable_slots', 'attendances', 'enrollments', 'subjects', 'teachers'] as $table) {
                    if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'classroom_id')) {
                        continue;
                    }
                    $query->whereNotExists(fn ($sub) => $sub
                        ->selectRaw('1')
                        ->from($table)
                        ->whereColumn($table.'.classroom_id', 'classrooms.id'));
                }
            })
            ->pluck('id');

        if ($emptyOrphans->isNotEmpty()) {
            DB::table('classroom_subject')->whereIn('classroom_id', $emptyOrphans)->delete();
            DB::table('classrooms')->whereIn('id', $emptyOrphans)->delete();
        }

        // 2. FK en cascade : garantie « tous chemins » au niveau base.
        Schema::table('classrooms', function (Blueprint $table): void {
            $table->dropForeign(['school_class_id']);
            $table->foreign('school_class_id')
                ->references('id')->on('school_classes')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('classrooms', function (Blueprint $table): void {
            $table->dropForeign(['school_class_id']);
            $table->foreign('school_class_id')
                ->references('id')->on('school_classes')
                ->nullOnDelete();
        });
    }
};
