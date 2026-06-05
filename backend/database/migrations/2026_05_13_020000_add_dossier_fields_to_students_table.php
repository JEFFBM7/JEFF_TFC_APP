<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->foreignId('enrollment_school_year_id')
                ->nullable()
                ->after('classroom_id')
                ->constrained('school_years')
                ->nullOnDelete();
            $table->string('place_of_birth', 100)->nullable()->after('date_of_birth');
            $table->string('nationality', 80)->nullable()->after('gender');
            $table->string('enrollment_status', 24)->default('actif')->after('photo_path');
            $table->string('order_number', 32)->nullable()->unique()->after('enrollment_status');
            $table->date('enrolled_on')->nullable()->after('order_number');
            $table->string('previous_school', 160)->nullable()->after('enrolled_on');
            $table->string('father_name', 160)->nullable()->after('previous_school');
            $table->string('mother_name', 160)->nullable()->after('father_name');
            $table->string('legal_guardian_name', 160)->nullable()->after('mother_name');
            $table->string('guardian_relationship', 80)->nullable()->after('legal_guardian_name');
            $table->string('primary_phone', 32)->nullable()->after('guardian_relationship');
            $table->string('secondary_phone', 32)->nullable()->after('primary_phone');
            $table->string('parent_email', 160)->nullable()->after('secondary_phone');
            $table->string('residential_address', 255)->nullable()->after('parent_email');
            $table->string('father_profession', 120)->nullable()->after('residential_address');
            $table->string('mother_profession', 120)->nullable()->after('father_profession');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropForeign(['enrollment_school_year_id']);
            $table->dropUnique(['order_number']);
            $table->dropColumn([
                'enrollment_school_year_id',
                'place_of_birth',
                'nationality',
                'enrollment_status',
                'order_number',
                'enrolled_on',
                'previous_school',
                'father_name',
                'mother_name',
                'legal_guardian_name',
                'guardian_relationship',
                'primary_phone',
                'secondary_phone',
                'parent_email',
                'residential_address',
                'father_profession',
                'mother_profession',
            ]);
        });
    }
};
