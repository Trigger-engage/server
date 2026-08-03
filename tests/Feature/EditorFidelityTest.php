<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsWorkspaces;
use Tests\TestCase;
use TriggerEngage\Server\Engine\EditorFidelity;
use TriggerEngage\Server\Models\Automation;
use TriggerEngage\Server\Models\Event;
use TriggerEngage\Server\Models\Workspace;

class EditorFidelityTest extends TestCase
{
    use BuildsWorkspaces;
    use RefreshDatabase;

    /**
     * A miniature of the seeded "New user activation" journey: a wait whose
     * timed-out path leads to a branch node the editor cannot represent.
     *
     * @return array{0: Workspace, 1: string, 2: Automation}
     */
    protected function makeLossyAutomation(): array
    {
        [$workspace, $key] = $this->makeWorkspace();
        $template = $this->makeEmailTemplate($workspace);
        $channel = $this->makeLogEmailChannel($workspace);
        $verified = Event::create(['workspace_id' => $workspace->id, 'name' => 'email_verified', 'first_seen_at' => now()]);

        $automation = $this->makeAutomation($workspace, 'user_registered', [
            'nodes' => [
                ['id' => 'trigger', 'type' => 'trigger', 'config' => []],
                ['id' => 'welcome', 'type' => 'send_email', 'config' => ['template_id' => $template->id, 'channel_id' => $channel->id, 'retry_attempts' => 3, 'on_failure' => 'continue']],
                ['id' => 'await_verify', 'type' => 'wait_for_event', 'config' => ['event_id' => $verified->id, 'event_name' => 'email_verified', 'timeout_days' => 0, 'timeout_hours' => 20, 'timeout_minutes' => 0, 'match_rules' => []]],
                ['id' => 'already_verified', 'type' => 'branch', 'config' => ['field' => 'person.email_verified', 'operator' => 'equals', 'value' => true]],
                ['id' => 'discovery', 'type' => 'send_email', 'config' => ['template_id' => $template->id, 'channel_id' => $channel->id, 'retry_attempts' => 3, 'on_failure' => 'continue']],
                ['id' => 'verify_nudge', 'type' => 'send_email', 'config' => ['template_id' => $template->id, 'channel_id' => $channel->id, 'retry_attempts' => 3, 'on_failure' => 'continue']],
                ['id' => 'done', 'type' => 'exit', 'config' => []],
            ],
            'edges' => [
                ['from' => 'trigger', 'to' => 'welcome'],
                ['from' => 'welcome', 'to' => 'await_verify'],
                // matched skips PAST the branch card; timed_out goes to the branch.
                ['from' => 'await_verify', 'to' => 'discovery', 'branch' => 'matched'],
                ['from' => 'await_verify', 'to' => 'already_verified', 'branch' => 'timed_out'],
                ['from' => 'already_verified', 'to' => 'discovery', 'branch' => 'true'],
                ['from' => 'already_verified', 'to' => 'verify_nudge', 'branch' => 'false'],
                ['from' => 'discovery', 'to' => 'done'],
                ['from' => 'verify_nudge', 'to' => 'done'],
            ],
        ]);

        return [$workspace, $key, $automation];
    }

    /** A linear steps payload the editor could legitimately submit. */
    protected function linearSteps(Workspace $workspace): array
    {
        $template = $workspace->templates()->first();
        $channel = $workspace->channels()->first();

        return [
            ['type' => 'delay', 'days' => 0, 'hours' => 0, 'minutes' => 10, 'until_time' => ''],
            ['type' => 'send_email', 'template_id' => $template->id, 'channel_id' => $channel->id, 'retry_attempts' => 3, 'on_failure' => 'continue'],
        ];
    }

    public function test_the_editor_is_told_when_a_graph_cannot_round_trip(): void
    {
        [$workspace, $key, $automation] = $this->makeLossyAutomation();

        $this->get("/app/automations/{$automation->id}", $this->authHeaders($workspace, $key))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Automations/Edit')
                ->has('fidelity')
                ->where('fidelity.0.message', fn ($message) => str_contains($message, 'branch'))
            );
    }

    public function test_publishing_a_lossy_graph_is_refused_without_acknowledgement(): void
    {
        [$workspace, $key, $automation] = $this->makeLossyAutomation();
        $versionsBefore = $automation->versions()->count();

        $this->put("/app/automations/{$automation->id}/publish", [
            'steps' => $this->linearSteps($workspace),
            'goal' => null,
        ], $this->authHeaders($workspace, $key))
            ->assertSessionHasErrors('fidelity');

        $this->assertSame($versionsBefore, $automation->versions()->count());
        // The active version — branch and all — is untouched.
        $this->assertContains('branch', collect($automation->fresh()->activeVersion->graph['nodes'])->pluck('type'));
    }

    public function test_an_acknowledged_publish_rewrites_the_graph(): void
    {
        [$workspace, $key, $automation] = $this->makeLossyAutomation();
        $versionsBefore = $automation->versions()->count();

        $this->put("/app/automations/{$automation->id}/publish", [
            'steps' => $this->linearSteps($workspace),
            'goal' => null,
            'acknowledge_lossy' => true,
        ], $this->authHeaders($workspace, $key))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $automation->refresh();
        $this->assertSame($versionsBefore + 1, $automation->versions()->count());
        $this->assertNotContains('branch', collect($automation->activeVersion->graph['nodes'])->pluck('type'));
    }

    public function test_editor_authored_graphs_are_faithful(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $template = $this->makeEmailTemplate($workspace);
        $channel = $this->makeLogEmailChannel($workspace);
        $event = Event::create(['workspace_id' => $workspace->id, 'name' => 'appointment_booked', 'first_seen_at' => now()]);
        $segment = $workspace->segments()->create(['name' => 'Actives', 'type' => 'manual']);
        $automation = $this->makeAutomation($workspace, 'user_registered', [
            'nodes' => [['id' => 'trigger', 'type' => 'trigger', 'config' => []], ['id' => 'exit', 'type' => 'exit', 'config' => []]],
            'edges' => [['from' => 'trigger', 'to' => 'exit']],
        ]);

        // Every editor shape at once: delay, segment filter, wait with a
        // send-then-continue timeout, an A/B split, wait with an exit timeout.
        $this->put("/app/automations/{$automation->id}/publish", [
            'steps' => [
                ['type' => 'delay', 'days' => 0, 'hours' => 1, 'minutes' => 0, 'until_time' => ''],
                ['type' => 'segment', 'segment_id' => $segment->id, 'in' => true],
                ['type' => 'wait_for_event', 'event_id' => $event->id, 'timeout_days' => 1, 'timeout_hours' => 0, 'timeout_minutes' => 0, 'timeout_action' => 'send_email', 'timeout_template_id' => $template->id, 'timeout_channel_id' => $channel->id],
                ['type' => 'split', 'variants' => [
                    ['key' => 'A', 'weight' => 50, 'type' => 'email', 'template_id' => $template->id, 'channel_id' => $channel->id],
                    ['key' => 'B', 'weight' => 50, 'type' => 'email', 'template_id' => $template->id, 'channel_id' => $channel->id],
                ]],
                ['type' => 'wait_for_event', 'event_id' => $event->id, 'timeout_days' => 0, 'timeout_hours' => 2, 'timeout_minutes' => 0, 'timeout_action' => 'exit'],
                ['type' => 'send_email', 'template_id' => $template->id, 'channel_id' => $channel->id],
            ],
            'goal' => ['enabled' => true, 'event_id' => $event->id],
        ], $this->authHeaders($workspace, $key))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame([], EditorFidelity::issues($automation->fresh()->activeVersion->graph));

        // And the edit page agrees.
        $this->get("/app/automations/{$automation->id}", $this->authHeaders($workspace, $key))
            ->assertInertia(fn (Assert $page) => $page->where('fidelity', []));
    }

    public function test_validation_errors_name_the_step_they_belong_to(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $this->makeEmailTemplate($workspace);
        $this->makeLogEmailChannel($workspace);
        $event = Event::create(['workspace_id' => $workspace->id, 'name' => 'appointment_booked', 'first_seen_at' => now()]);
        $automation = $this->makeAutomation($workspace, 'user_registered', [
            'nodes' => [['id' => 'trigger', 'type' => 'trigger', 'config' => []], ['id' => 'exit', 'type' => 'exit', 'config' => []]],
            'edges' => [['from' => 'trigger', 'to' => 'exit']],
        ]);

        // Step 2 (index 1) is a wait with a zero timeout — invalid.
        $response = $this->put("/app/automations/{$automation->id}/publish", [
            'steps' => [
                ['type' => 'delay', 'days' => 0, 'hours' => 0, 'minutes' => 5, 'until_time' => ''],
                ['type' => 'wait_for_event', 'event_id' => $event->id, 'timeout_days' => 0, 'timeout_hours' => 0, 'timeout_minutes' => 0, 'timeout_action' => 'continue'],
            ],
            'goal' => null,
        ], $this->authHeaders($workspace, $key));

        $response->assertSessionHasErrors('steps.1');
        $this->assertStringContainsString('Step 2:', session('errors')->first('steps.1'));
    }

    public function test_rich_correlation_and_extra_goals_are_flagged(): void
    {
        $graph = [
            'nodes' => [
                ['id' => 'trigger', 'type' => 'trigger', 'config' => []],
                ['id' => 'wait', 'type' => 'wait_for_event', 'config' => ['event_id' => 1, 'match_rules' => [
                    ['incoming_field' => 'a', 'operator' => 'equals', 'source' => 'trigger_event', 'source_field' => 'a'],
                    ['incoming_field' => 'b', 'operator' => 'equals', 'source' => 'person', 'source_field' => 'b'],
                ]]],
                ['id' => 'exit', 'type' => 'exit', 'config' => []],
            ],
            'edges' => [
                ['from' => 'trigger', 'to' => 'wait'],
                ['from' => 'wait', 'to' => 'exit', 'branch' => 'matched'],
                ['from' => 'wait', 'to' => 'exit', 'branch' => 'timed_out'],
            ],
            'goals' => [
                ['id' => 'goal_1', 'event_id' => 1, 'match_rules' => []],
                ['id' => 'goal_2', 'event_id' => 2, 'match_rules' => []],
            ],
        ];

        $messages = collect(EditorFidelity::issues($graph))->pluck('message');

        $this->assertTrue($messages->contains(fn ($message) => str_contains($message, 'correlation rules')));
        $this->assertTrue($messages->contains(fn ($message) => str_contains($message, 'goals')));
    }

    public function test_an_acknowledged_publish_still_enforces_step_validation(): void
    {
        [$workspace, $key, $automation] = $this->makeLossyAutomation();
        $versionsBefore = $automation->versions()->count();
        $event = $workspace->events()->where('name', 'email_verified')->first();

        // Acknowledged, but step 2 is a wait with a zero timeout — invalid.
        $this->put("/app/automations/{$automation->id}/publish", [
            'steps' => [
                ['type' => 'delay', 'days' => 0, 'hours' => 0, 'minutes' => 5, 'until_time' => ''],
                ['type' => 'wait_for_event', 'event_id' => $event->id, 'timeout_days' => 0, 'timeout_hours' => 0, 'timeout_minutes' => 0, 'timeout_action' => 'continue'],
            ],
            'goal' => null,
            'acknowledge_lossy' => true,
        ], $this->authHeaders($workspace, $key))
            ->assertSessionHasErrors('steps.1');

        $this->assertSame($versionsBefore, $automation->versions()->count());
    }

    public function test_the_fidelity_refusal_names_the_issues(): void
    {
        [$workspace, $key, $automation] = $this->makeLossyAutomation();

        $response = $this->put("/app/automations/{$automation->id}/publish", [
            'steps' => $this->linearSteps($workspace),
            'goal' => null,
        ], $this->authHeaders($workspace, $key));

        $response->assertSessionHasErrors('fidelity');
        $this->assertStringContainsString('branch', session('errors')->getBag('default')->first('fidelity'));
    }

    public function test_missing_edges_are_flagged_as_loss(): void
    {
        // Dead-end step: 'a' has no outgoing edge, so today the run STOPS there
        // and 'b' never sends. A republish would splice b back onto the spine —
        // the literal "both emails" incident shape.
        $deadEnd = [
            'nodes' => [
                ['id' => 'trigger', 'type' => 'trigger', 'config' => []],
                ['id' => 'a', 'type' => 'send_email', 'config' => ['template_id' => 1, 'channel_id' => 1]],
                ['id' => 'b', 'type' => 'send_email', 'config' => ['template_id' => 2, 'channel_id' => 1]],
                ['id' => 'exit', 'type' => 'exit', 'config' => []],
            ],
            'edges' => [['from' => 'trigger', 'to' => 'a']],
        ];

        $this->assertNotSame([], EditorFidelity::issues($deadEnd));

        // A wait missing its matched edge ends the run on match today; the
        // rebuild would continue it.
        $waitNoMatched = [
            'nodes' => [
                ['id' => 'trigger', 'type' => 'trigger', 'config' => []],
                ['id' => 'w', 'type' => 'wait_for_event', 'config' => ['event_id' => 1]],
                ['id' => 'exit', 'type' => 'exit', 'config' => []],
            ],
            'edges' => [
                ['from' => 'trigger', 'to' => 'w'],
                ['from' => 'w', 'to' => 'exit', 'branch' => 'timed_out'],
            ],
        ];

        $messages = collect(EditorFidelity::issues($waitNoMatched))->pluck('message');
        $this->assertTrue($messages->contains(fn ($message) => str_contains($message, 'matched/timed-out')));
    }

    public function test_an_unlabeled_edge_shadowing_wait_branches_is_flagged(): void
    {
        // Graph::after() lets an unlabeled edge answer for BOTH outcomes; the
        // rebuild drops it and behaviour flips.
        $graph = [
            'nodes' => [
                ['id' => 'trigger', 'type' => 'trigger', 'config' => []],
                ['id' => 'w', 'type' => 'wait_for_event', 'config' => ['event_id' => 1]],
                ['id' => 'send', 'type' => 'send_email', 'config' => ['template_id' => 1, 'channel_id' => 1]],
                ['id' => 'exit', 'type' => 'exit', 'config' => []],
            ],
            'edges' => [
                ['from' => 'trigger', 'to' => 'w'],
                ['from' => 'w', 'to' => 'send'],
                ['from' => 'w', 'to' => 'send', 'branch' => 'matched'],
                ['from' => 'w', 'to' => 'exit', 'branch' => 'timed_out'],
                ['from' => 'send', 'to' => 'exit'],
            ],
        ];

        $messages = collect(EditorFidelity::issues($graph))->pluck('message');
        $this->assertTrue($messages->contains(fn ($message) => str_contains($message, 'unlabeled')));
    }

    public function test_correlation_only_in_match_rules_is_flagged(): void
    {
        // The scalars are what the editor round-trips; rules without them are
        // silently dropped on republish, making the wait resume on ANY event.
        $graph = [
            'nodes' => [
                ['id' => 'trigger', 'type' => 'trigger', 'config' => []],
                ['id' => 'w', 'type' => 'wait_for_event', 'config' => [
                    'event_id' => 1,
                    'match_rules' => [['incoming_field' => 'appointment_id', 'operator' => 'equals', 'source' => 'trigger_event', 'source_field' => 'appointment_id']],
                ]],
                ['id' => 'exit', 'type' => 'exit', 'config' => []],
            ],
            'edges' => [
                ['from' => 'trigger', 'to' => 'w'],
                ['from' => 'w', 'to' => 'exit', 'branch' => 'matched'],
                ['from' => 'w', 'to' => 'exit', 'branch' => 'timed_out'],
            ],
        ];

        $messages = collect(EditorFidelity::issues($graph))->pluck('message');
        $this->assertTrue($messages->contains(fn ($message) => str_contains($message, 'correlation')));
    }

    public function test_goal_correlation_only_in_match_rules_is_flagged(): void
    {
        // The seeded Wellness Step journey's shape: goal correlation lives only
        // in match_rules. A republish empties it, and completing ANY step would
        // then complete EVERY pending run for the person.
        $graph = [
            'nodes' => [
                ['id' => 'trigger', 'type' => 'trigger', 'config' => []],
                ['id' => 'd', 'type' => 'delay', 'config' => ['minutes' => 5]],
                ['id' => 'exit', 'type' => 'exit', 'config' => []],
            ],
            'edges' => [['from' => 'trigger', 'to' => 'd'], ['from' => 'd', 'to' => 'exit']],
            'goals' => [[
                'id' => 'goal_1', 'event_id' => 1,
                'match_rules' => [['incoming_field' => 'wellness_step_id', 'operator' => 'equals', 'source' => 'trigger_event', 'source_field' => 'wellness_step_id']],
            ]],
        ];

        $messages = collect(EditorFidelity::issues($graph))->pluck('message');
        $this->assertTrue($messages->contains(fn ($message) => str_contains($message, 'goal')));
    }

    public function test_timeout_action_contradicting_the_edge_is_flagged(): void
    {
        $graph = [
            'nodes' => [
                ['id' => 'trigger', 'type' => 'trigger', 'config' => []],
                ['id' => 'w', 'type' => 'wait_for_event', 'config' => ['event_id' => 1, 'timeout_action' => 'continue']],
                ['id' => 'send', 'type' => 'send_email', 'config' => ['template_id' => 1, 'channel_id' => 1]],
                ['id' => 'exit', 'type' => 'exit', 'config' => []],
            ],
            'edges' => [
                ['from' => 'trigger', 'to' => 'w'],
                ['from' => 'w', 'to' => 'send', 'branch' => 'matched'],
                // The edge stops the run on timeout; the config says continue.
                // The rebuild trusts the config, so behaviour would flip.
                ['from' => 'w', 'to' => 'exit', 'branch' => 'timed_out'],
                ['from' => 'send', 'to' => 'exit'],
            ],
        ];

        $messages = collect(EditorFidelity::issues($graph))->pluck('message');
        $this->assertTrue($messages->contains(fn ($message) => str_contains($message, 'timeout')));
    }

    public function test_split_variants_out_of_sync_with_generated_nodes_are_flagged(): void
    {
        $graph = [
            'nodes' => [
                ['id' => 'trigger', 'type' => 'trigger', 'config' => []],
                ['id' => 'ab', 'type' => 'split', 'config' => ['variants' => [
                    ['key' => 'A', 'weight' => 50, 'type' => 'email', 'template_id' => 7, 'channel_id' => 1],
                    ['key' => 'B', 'weight' => 50, 'type' => 'email', 'template_id' => 8, 'channel_id' => 1],
                ]]],
                // The engine executes THIS node — template 42, not the 7 the
                // variants list (and a republish) would use.
                ['id' => 'ab__v_A', 'type' => 'send_email', 'config' => ['template_id' => 42, 'channel_id' => 1, 'generated_for_split' => 'ab', 'variant' => 'A']],
                ['id' => 'ab__v_B', 'type' => 'send_email', 'config' => ['template_id' => 8, 'channel_id' => 1, 'generated_for_split' => 'ab', 'variant' => 'B']],
                ['id' => 'exit', 'type' => 'exit', 'config' => []],
            ],
            'edges' => [
                ['from' => 'trigger', 'to' => 'ab'],
                ['from' => 'ab', 'to' => 'ab__v_A', 'branch' => 'A'],
                ['from' => 'ab', 'to' => 'ab__v_B', 'branch' => 'B'],
                ['from' => 'ab__v_A', 'to' => 'exit'],
                ['from' => 'ab__v_B', 'to' => 'exit'],
            ],
        ];

        $messages = collect(EditorFidelity::issues($graph))->pluck('message');
        $this->assertTrue($messages->contains(fn ($message) => str_contains($message, 'out of sync')));
    }

    public function test_duplicate_node_ids_are_flagged(): void
    {
        $graph = [
            'nodes' => [
                ['id' => 'trigger', 'type' => 'trigger', 'config' => []],
                ['id' => 's', 'type' => 'send_email', 'config' => ['template_id' => 1, 'channel_id' => 1]],
                ['id' => 's', 'type' => 'send_email', 'config' => ['template_id' => 2, 'channel_id' => 1]],
                ['id' => 'exit', 'type' => 'exit', 'config' => []],
            ],
            'edges' => [['from' => 'trigger', 'to' => 's'], ['from' => 's', 'to' => 'exit']],
        ];

        $messages = collect(EditorFidelity::issues($graph))->pluck('message');
        $this->assertTrue($messages->contains(fn ($message) => str_contains($message, 'share the id')));
    }

    public function test_a_custom_retry_backoff_is_flagged(): void
    {
        $graph = [
            'nodes' => [
                ['id' => 'trigger', 'type' => 'trigger', 'config' => []],
                ['id' => 's', 'type' => 'send_email', 'config' => ['template_id' => 1, 'channel_id' => 1, 'retry_backoff_seconds' => [300, 3600, 86400]]],
                ['id' => 'exit', 'type' => 'exit', 'config' => []],
            ],
            'edges' => [['from' => 'trigger', 'to' => 's'], ['from' => 's', 'to' => 'exit']],
        ];

        $messages = collect(EditorFidelity::issues($graph))->pluck('message');
        $this->assertTrue($messages->contains(fn ($message) => str_contains($message, 'retry backoff')));
    }

    public function test_a_timeout_stopping_at_a_renamed_exit_round_trips_faithfully(): void
    {
        // Seeded journeys name their exit 'done'. A mid-chain wait whose
        // timed-out path stops there must infer timeout_action 'exit' (by node
        // TYPE), not 'continue' — and therefore be faithful.
        $graph = [
            'nodes' => [
                ['id' => 'trigger', 'type' => 'trigger', 'config' => []],
                ['id' => 'w', 'type' => 'wait_for_event', 'config' => ['event_id' => 1]],
                ['id' => 'send', 'type' => 'send_email', 'config' => ['template_id' => 1, 'channel_id' => 1]],
                ['id' => 'done', 'type' => 'exit', 'config' => []],
            ],
            'edges' => [
                ['from' => 'trigger', 'to' => 'w'],
                ['from' => 'w', 'to' => 'send', 'branch' => 'matched'],
                ['from' => 'w', 'to' => 'done', 'branch' => 'timed_out'],
                ['from' => 'send', 'to' => 'done'],
            ],
        ];

        $this->assertSame([], EditorFidelity::issues($graph));
    }

    public function test_a_spine_that_skips_a_step_is_flagged(): void
    {
        $graph = [
            'nodes' => [
                ['id' => 'trigger', 'type' => 'trigger', 'config' => []],
                ['id' => 'a', 'type' => 'delay', 'config' => ['minutes' => 5]],
                ['id' => 'b', 'type' => 'delay', 'config' => ['minutes' => 5]],
                ['id' => 'c', 'type' => 'delay', 'config' => ['minutes' => 5]],
                ['id' => 'exit', 'type' => 'exit', 'config' => []],
            ],
            'edges' => [
                ['from' => 'trigger', 'to' => 'a'],
                // The stored spine skips b entirely — the editor's rebuild
                // would splice it back in, changing behaviour.
                ['from' => 'a', 'to' => 'c'],
                ['from' => 'c', 'to' => 'exit'],
            ],
        ];

        $messages = collect(EditorFidelity::issues($graph))->pluck('message');

        $this->assertTrue($messages->contains(fn ($message) => str_contains($message, 'skips over')));
    }
}
