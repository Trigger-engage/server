<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            // A person can now exist before they are known: an anonymous visitor
            // has an anonymous_id and a null external_id until they identify.
            $table->string('external_id')->nullable()->change();
            $table->string('anonymous_id')->nullable()->after('external_id');
            $table->unique(['workspace_id', 'anonymous_id']);
        });

        Schema::table('event_occurrences', function (Blueprint $table) {
            $table->string('anonymous_id')->nullable()->after('person_id');
        });
    }

    public function down(): void
    {
        Schema::table('event_occurrences', function (Blueprint $table) {
            $table->dropColumn('anonymous_id');
        });

        Schema::table('people', function (Blueprint $table) {
            $table->dropUnique(['workspace_id', 'anonymous_id']);
            $table->dropColumn('anonymous_id');
            $table->string('external_id')->nullable(false)->change();
        });
    }
};
