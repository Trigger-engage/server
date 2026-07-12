<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('channel'); // email | sms | push
            $table->string('name');
            $table->string('subject')->nullable();
            $table->text('body');
            $table->string('from_name')->nullable();
            $table->string('from_address')->nullable();
            $table->timestamps();
        });

        Schema::create('channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // email | sms | push
            $table->string('driver'); // smtp | log | (later: zeptomail, termii, onesignal, ...)
            $table->string('name');
            $table->text('credentials')->nullable(); // encrypted json
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->foreignId('run_step_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('template_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel');
            $table->string('to_address');
            $table->string('subject')->nullable();
            $table->text('body')->nullable(); // rendered snapshot
            $table->string('status')->default('queued'); // queued | sending | sent | delivered | bounced | failed
            $table->string('provider_message_id')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'person_id']);
        });

        Schema::create('suppressions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->string('channel'); // email | sms | push | all
            $table->string('reason'); // unsubscribe | bounce | complaint | manual
            $table->timestamps();

            $table->unique(['workspace_id', 'person_id', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppressions');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('channels');
        Schema::dropIfExists('templates');
    }
};
