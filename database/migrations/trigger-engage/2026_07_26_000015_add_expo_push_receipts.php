<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expo has no delivery webhook. A send returns a "ticket" per device token,
 * and final delivery is confirmed by polling /push/getReceipts with those
 * ticket ids. A person can hold several tokens (phone + tablet), so one
 * message row can be waiting on several receipts — hence a list, not a column
 * alongside provider_message_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->json('pending_receipts')->nullable()->after('provider_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('pending_receipts');
        });
    }
};
