<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            $table->string('layout')->default('mytherapist')->after('body');
            $table->string('preheader')->nullable()->after('layout');
            $table->json('settings')->nullable()->after('preheader');
        });
    }

    public function down(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            $table->dropColumn(['layout', 'preheader', 'settings']);
        });
    }
};
