<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            $table->string('registration_number', 32)->nullable()->unique()->after('user_id');
            $table->string('gender', 1)->nullable()->after('registration_number');
            $table->date('birth_date')->nullable()->after('gender');
            $table->string('address', 255)->nullable()->after('birth_date');
            $table->string('grade', 128)->nullable()->after('address');
            $table->string('contract_type', 32)->nullable()->after('grade');
            $table->date('hired_on')->nullable()->after('contract_type');
        });
    }

    public function down(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            $table->dropUnique(['registration_number']);
            $table->dropColumn([
                'registration_number',
                'gender',
                'birth_date',
                'address',
                'grade',
                'contract_type',
                'hired_on',
            ]);
        });
    }
};
