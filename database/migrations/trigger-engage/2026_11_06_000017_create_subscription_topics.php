<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Prefixed like engage_api_keys: these names are generic enough to
        // collide with a host application's own tables in embedded mode.
        Schema::create('engage_subscription_topics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('name');
            $table->text('description')->nullable();
            // true = everyone gets it until they opt out; false = opt-in only.
            $table->boolean('default_subscribed')->default(true);
            // When set, the person's true/false property of this name is the
            // preference, so a host app's own setting stays the one source.
            $table->string('property')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'key']);
        });

        Schema::create('engage_subscription_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->foreignId('subscription_topic_id')->constrained('engage_subscription_topics')->cascadeOnDelete();
            $table->boolean('subscribed');
            $table->string('source'); // preference_center | unsubscribe_link | api | dashboard
            $table->timestamps();

            $table->unique(['person_id', 'subscription_topic_id'], 'engage_subscription_preferences_person_topic_unique');
            $table->index(['subscription_topic_id', 'subscribed']);
        });

        foreach (['templates', 'broadcasts', 'messages'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('subscription_topic_id')->nullable()->constrained('engage_subscription_topics')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['messages', 'broadcasts', 'templates'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropConstrainedForeignId('subscription_topic_id');
            });
        }

        Schema::dropIfExists('engage_subscription_preferences');
        Schema::dropIfExists('engage_subscription_topics');
    }
};
