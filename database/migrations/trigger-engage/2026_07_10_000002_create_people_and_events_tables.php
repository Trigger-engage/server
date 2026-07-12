<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('people', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('external_id');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->json('attributes')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'external_id']);
            $table->index(['workspace_id', 'email']);
        });

        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->json('payload_schema')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'name']);
        });

        Schema::create('event_occurrences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->nullable()->constrained('people')->nullOnDelete();
            $table->json('payload')->nullable();
            $table->string('idempotency_key')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->unique(['workspace_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_occurrences');
        Schema::dropIfExists('events');
        Schema::dropIfExists('people');
    }
};
