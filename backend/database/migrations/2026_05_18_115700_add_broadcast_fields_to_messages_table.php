<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->boolean('is_announcement')->default(false)->after('body');
            $table->uuid('broadcast_id')->nullable()->after('is_announcement');
            $table->index('broadcast_id');
            $table->index(['recipient_id', 'is_announcement', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['recipient_id', 'is_announcement', 'read_at']);
            $table->dropIndex(['broadcast_id']);
            $table->dropColumn(['is_announcement', 'broadcast_id']);
        });
    }
};
