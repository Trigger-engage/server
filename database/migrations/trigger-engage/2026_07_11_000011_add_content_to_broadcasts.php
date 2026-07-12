<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A broadcast owns a point-in-time snapshot of its message content,
        // seeded from the chosen template but editable per send. Nullable so a
        // broadcast falls back to its template until the content is composed.
        Schema::table('broadcasts', function (Blueprint $table) {
            $table->string('subject')->nullable()->after('channel');
            $table->text('body')->nullable()->after('subject');
            $table->string('layout')->nullable()->after('body');
            $table->string('preheader')->nullable()->after('layout');
            $table->json('settings')->nullable()->after('preheader');
            $table->string('from_name')->nullable()->after('settings');
            $table->string('from_address')->nullable()->after('from_name');
        });
    }

    public function down(): void
    {
        Schema::table('broadcasts', function (Blueprint $table) {
            $table->dropColumn(['subject', 'body', 'layout', 'preheader', 'settings', 'from_name', 'from_address']);
        });
    }
};
