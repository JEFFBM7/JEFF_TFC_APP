<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('terms', function (Blueprint $table): void {
            $table->string('term_type', 20)->default('trimestre')->after('position');
            $table->string('applicable_cycle', 20)->default('primaire')->after('term_type');
        });

        // Tous les terms existants sont des trimestres du cycle primaire
        DB::table('terms')->update([
            'term_type'        => 'trimestre',
            'applicable_cycle' => 'primaire',
        ]);

        // Supprimer l'ancienne contrainte `max:3` sur la position (qui sera portée à max:5)
        // et autoriser les positions 4 et 5 pour les semestres secondaires.
        // La contrainte unique (school_year_id, position) existante permet déjà 1..N,
        // mais on retire l'unique sur (school_year_id, name) pour permettre
        // d'avoir "Période 1" dans trimestre ET semestre.
        // → Rien à faire en DB pour cela : le nom unique est par term_id, pas par school_year_id.
    }

    public function down(): void
    {
        Schema::table('terms', function (Blueprint $table): void {
            $table->dropColumn(['term_type', 'applicable_cycle']);
        });
    }
};
