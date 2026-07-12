<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('status')->default('draft'); // draft | active | paused
            $table->foreignId('trigger_event_id')->nullable()->constrained('events')->nullOnDelete();
            // every_time | one_active_run_per_person | once_ever_per_person
            $table->string('reentry_policy')->default('every_time');
            $table->unsignedBigInteger('active_version_id')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status', 'trigger_event_id']);
        });

        Schema::create('automation_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_id')->constrained()->cascadeOnDelete();
            $table->json('graph');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('automation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->foreignId('event_occurrence_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('running'); // running | waiting | waiting_event | completed | cancelled | failed
            $table->string('current_node_id')->nullable();
            $table->timestamp('wake_at')->nullable();
            $table->json('context')->nullable();
            $table->timestamps();

            $table->index(['status', 'wake_at']);
            $table->index(['automation_id', 'person_id', 'status']);
        });

        Schema::create('run_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_run_id')->constrained()->cascadeOnDelete();
            $table->string('node_id');
            $table->string('type');
            $table->string('status'); // processing | retrying | completed | skipped | failed
            $table->json('output')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('executed_at');
            $table->timestamps();

            // One execution record per node per run — this is the double-send guard.
            $table->unique(['automation_run_id', 'node_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('run_steps');
        Schema::dropIfExists('automation_runs');
        Schema::dropIfExists('automation_versions');
        Schema::dropIfExists('automations');
    }
};
