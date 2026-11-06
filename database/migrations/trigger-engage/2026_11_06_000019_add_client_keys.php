<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engage_api_keys', function (Blueprint $table) {
            // server: the secret key for the full API. client: a publishable
            // key an app can ship, limited to the client endpoints.
            $table->string('kind', 16)->default('server');
            // Client keys only. The public key is not a secret, so it is kept
            // to show again; the identity secret signs person ids (HMAC) on
            // the host's server and is stored encrypted.
            $table->string('public_key', 80)->nullable();
            $table->text('identity_secret')->nullable();
            $table->json('allowed_origins')->nullable();
            $table->json('allowed_events')->nullable();
            $table->timestamp('revoked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('engage_api_keys', function (Blueprint $table) {
            $table->dropColumn(['kind', 'public_key', 'identity_secret', 'allowed_origins', 'allowed_events', 'revoked_at']);
        });
    }
};
