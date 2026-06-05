<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table): void {
            $table->text('student_justification')->nullable()->after('justification');
            $table->timestamp('student_justified_at')->nullable()->after('student_justification');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table): void {
            $table->dropColumn(['student_justification', 'student_justified_at']);
        });
    }
};
