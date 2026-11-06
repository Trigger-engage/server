<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            // Logo, colours, footer copy and links for every branded email in
            // the workspace. Null keys fall back to config('email_templates').
            $table->json('brand')->nullable();
        });

        // Until now the editor saved every brand key onto every email template
        // and broadcast, so none of them could follow a workspace brand. Keep
        // only the keys that differ from the defaults they were edited against:
        // with no workspace brand yet, each one renders exactly as before.
        $defaults = config('email_templates.mytherapist', []);

        foreach (['templates', 'broadcasts'] as $table) {
            DB::table($table)->where('channel', 'email')->whereNotNull('settings')->orderBy('id')
                ->each(function (object $row) use ($table, $defaults): void {
                    $settings = json_decode((string) $row->settings, true);
                    if (! is_array($settings)) {
                        return;
                    }

                    $overrides = array_filter(
                        $settings,
                        fn ($value, string $key) => ! array_key_exists($key, $defaults) || ! self::same($value, $defaults[$key]),
                        ARRAY_FILTER_USE_BOTH,
                    );

                    if ($overrides !== $settings) {
                        DB::table($table)->where('id', $row->id)->update(['settings' => $overrides === [] ? null : json_encode($overrides)]);
                    }
                });
        }
    }

    public function down(): void
    {
        // Slimmed settings render identically, so only the column goes.
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('brand');
        });
    }

    private static function same(mixed $value, mixed $default): bool
    {
        if (is_string($value) && is_string($default)) {
            // The colour picker upper-cases hex values; copy is compared exactly.
            return str_starts_with($default, '#')
                ? strcasecmp(trim($value), $default) === 0
                : trim($value) === trim($default);
        }

        return $value === $default;
    }
};
