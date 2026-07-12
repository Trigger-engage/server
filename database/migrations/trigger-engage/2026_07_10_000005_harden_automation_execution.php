<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('automation_runs', function (Blueprint $table) {
            // A queue retry of the matcher must not create a second run for
            // the same automation and occurrence.
            $table->unique(
                ['automation_id', 'event_occurrence_id'],
                'automation_runs_automation_occurrence_unique'
            );
        });

        Schema::table('run_steps', function (Blueprint $table) {
            $table->unsignedSmallInteger('attempts')->default(0)->after('status');
            $table->timestamp('next_attempt_at')->nullable()->after('attempts');
        });

        Schema::table('messages', function (Blueprint $table) {
            // One message ledger entry per send action. Retries update the
            // same row instead of creating a second message.
            $table->unique('run_step_id');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropUnique(['run_step_id']);
        });

        Schema::table('run_steps', function (Blueprint $table) {
            $table->dropColumn(['attempts', 'next_attempt_at']);
        });

        Schema::table('automation_runs', function (Blueprint $table) {
            $table->dropUnique('automation_runs_automation_occurrence_unique');
        });
    }
};
