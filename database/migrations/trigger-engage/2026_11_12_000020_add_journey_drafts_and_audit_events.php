<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A journey's steps only ever lived in published versions, so there was
        // nowhere to keep an unpublished change. The draft sits beside the live
        // version until someone publishes it from the editor or discards it.
        Schema::table('automations', function (Blueprint $table) {
            $table->json('draft_graph')->nullable()->after('active_version_id');
            $table->timestamp('draft_saved_at')->nullable()->after('draft_graph');
            $table->string('draft_saved_by')->nullable()->after('draft_saved_at');
        });

        // What MCP clients (AI agents) changed, and on whose credentials.
        Schema::create('engage_audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('actor');
            $table->string('action', 64);
            $table->string('subject_type', 32);
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('summary', 500);
            $table->json('details')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['workspace_id', 'created_at'], 'engage_audit_events_workspace_created_index');
            $table->index(['subject_type', 'subject_id'], 'engage_audit_events_subject_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engage_audit_events');

        Schema::table('automations', function (Blueprint $table) {
            $table->dropColumn(['draft_graph', 'draft_saved_at', 'draft_saved_by']);
        });
    }
};
