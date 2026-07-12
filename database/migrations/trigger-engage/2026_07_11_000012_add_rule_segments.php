<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('segments', function (Blueprint $table) {
            // Boolean rule group for type=rule segments, e.g.
            // {"match":"all","conditions":[{"kind":"attribute",...},{"kind":"event",...}]}
            $table->json('rules')->nullable()->after('event_id');
            // When the rule membership was last fully recomputed (drives the
            // periodic sweep that catches purely time-based conditions).
            $table->timestamp('recomputed_at')->nullable()->after('rules');
        });
    }

    public function down(): void
    {
        Schema::table('segments', function (Blueprint $table) {
            $table->dropColumn(['rules', 'recomputed_at']);
        });
    }
};
