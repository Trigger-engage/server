<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('public_id')->unique();
            $table->string('name');
            $table->string('type'); // manual | event
            $table->foreignId('event_id')->nullable()->constrained()->nullOnDelete();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'name']);
            $table->index(['workspace_id', 'type', 'event_id']);
        });

        Schema::create('segment_person', function (Blueprint $table) {
            $table->id();
            $table->foreignId('segment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->string('source'); // api | event
            $table->foreignId('event_occurrence_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('added_at');
            $table->unique(['segment_id', 'person_id']);
            $table->index(['person_id', 'segment_id']);
        });

        Schema::create('broadcasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('segment_id')->constrained()->restrictOnDelete();
            $table->foreignId('template_id')->constrained()->restrictOnDelete();
            $table->foreignId('channel_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('channel');
            $table->string('status')->default('draft');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'status']);
        });

        Schema::create('broadcast_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('broadcast_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('queued');
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['broadcast_id', 'person_id']);
            $table->index(['broadcast_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broadcast_recipients');
        Schema::dropIfExists('broadcasts');
        Schema::dropIfExists('segment_person');
        Schema::dropIfExists('segments');
    }
};
