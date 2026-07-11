<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->timestamp('delivered_at')->nullable()->after('sent_at');
            $table->timestamp('opened_at')->nullable()->after('delivered_at');
            $table->timestamp('clicked_at')->nullable()->after('opened_at');
            $table->timestamp('bounced_at')->nullable()->after('clicked_at');
            $table->index(['workspace_id', 'channel', 'status']);
        });

        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('provider_event_id');
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');

        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'channel', 'status']);
            $table->dropColumn(['delivered_at', 'opened_at', 'clicked_at', 'bounced_at']);
        });
    }
};
