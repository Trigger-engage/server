<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('run_event_waits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('node_id');
            $table->string('status')->default('waiting');
            $table->json('match_rules')->nullable();
            $table->unsignedBigInteger('occurrence_cursor')->default(0);
            $table->foreignId('matched_occurrence_id')->nullable()->constrained('event_occurrences')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('matched_at')->nullable();
            $table->timestamp('timed_out_at')->nullable();
            $table->timestamps();

            $table->unique(['automation_run_id', 'node_id']);
            $table->index(['workspace_id', 'person_id', 'event_id', 'status'], 'event_wait_match_index');
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('run_event_waits');
    }
};
