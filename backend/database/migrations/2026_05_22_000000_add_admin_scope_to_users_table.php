<?php

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('admin_scope', 32)->nullable()->after('role');
        });

        DB::table('users')
            ->where('role', UserRole::Admin->value)
            ->whereNull('admin_scope')
            ->update(['admin_scope' => 'global']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('admin_scope');
        });
    }
};
