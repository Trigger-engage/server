<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('workspaces')->orderBy('id')->each(function ($workspace): void {
            $segmentId = DB::table('segments')
                ->where('workspace_id', $workspace->id)
                ->where('type', 'all')
                ->value('id');

            if (! $segmentId) {
                $name = DB::table('segments')
                    ->where('workspace_id', $workspace->id)
                    ->where('name', 'All people')
                    ->exists() ? 'All people (default)' : 'All people';

                $segmentId = DB::table('segments')->insertGetId([
                    'workspace_id' => $workspace->id,
                    'public_id' => 'seg_'.strtolower((string) Str::ulid()),
                    'name' => $name,
                    'type' => 'all',
                    'description' => 'Every profile in this workspace. Membership updates automatically.',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('people')
                ->where('workspace_id', $workspace->id)
                ->select('id')
                ->orderBy('id')
                ->chunk(500, function ($people) use ($segmentId): void {
                    $now = now();
                    DB::table('segment_person')->insertOrIgnore(
                        $people->map(fn ($person) => [
                            'segment_id' => $segmentId,
                            'person_id' => $person->id,
                            'source' => 'system',
                            'added_at' => $now,
                        ])->all()
                    );
                });
        });
    }

    public function down(): void
    {
        // Preserve broadcasts that already reference this audience.
        DB::table('segments')->where('type', 'all')->update(['type' => 'manual']);
    }
};
