<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * engage:tick re-dispatches runs stranded `running` by a lost advance job.
 * Dispatching changes nothing on the run, so without a record of the attempt
 * the same lowest-id runs would be picked every minute while the queue or the
 * lock store is still catching up. Kept apart from updated_at, which measures
 * how long the run has truly been stuck and drives the give-up deadline.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('automation_runs', function (Blueprint $table) {
            $table->timestamp('recovery_attempted_at')->nullable()->after('wake_at');
        });
    }

    public function down(): void
    {
        Schema::table('automation_runs', function (Blueprint $table) {
            $table->dropColumn('recovery_attempted_at');
        });
    }
};
