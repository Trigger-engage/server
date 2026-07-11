<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('run_goal_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('goal_id');
            $table->string('status')->default('active');
            $table->json('match_rules')->nullable();
            $table->unsignedBigInteger('occurrence_cursor')->default(0);
            $table->foreignId('reached_occurrence_id')->nullable()->constrained('event_occurrences')->nullOnDelete();
            $table->timestamp('reached_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['automation_run_id', 'goal_id']);
            $table->index(['workspace_id', 'person_id', 'event_id', 'status'], 'goal_subscription_match_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('run_goal_subscriptions');
    }
};
