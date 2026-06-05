<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('school_options', function (Blueprint $table) {
            // Filière des humanités : 'generale' | 'technique' | 'professionnelle'
            // Source : système EPST/RDC (edu-nc.gouv.cd/niveaux).
            $table->string('filiere', 32)->nullable()->after('name')->index();
        });
    }

    public function down(): void
    {
        Schema::table('school_options', function (Blueprint $table) {
            $table->dropIndex(['filiere']);
            $table->dropColumn('filiere');
        });
    }
};
