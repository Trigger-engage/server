<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsWorkspaces;
use Tests\Concerns\CallsMcp;
use Tests\TestCase;
use TriggerEngage\Server\Mcp\ToolRegistry;
use TriggerEngage\Server\Models\ApiKey;
use TriggerEngage\Server\Models\Person;

class McpServerTest extends TestCase
{
    use BuildsWorkspaces, CallsMcp, RefreshDatabase;

    public function test_initialize_negotiates_the_protocol_version_and_describes_the_server(): void
    {
        [, $key] = $this->makeWorkspace();

        $result = $this->mcp($key, 'initialize', ['protocolVersion' => '2025-03-26', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'test', 'version' => '1']])
            ->assertOk()->assertJsonPath('jsonrpc', '2.0')->assertJsonPath('id', 1)->json('result');

        $this->assertSame('2025-03-26', $result['protocolVersion']);
        $this->assertSame('trigger-engage', $result['serverInfo']['name']);
        $this->assertSame(['tools' => ['listChanged' => false]], $result['capabilities']);
        $this->assertStringContainsString('read-only', $result['instructions']);

        $this->mcp($key, 'initialize', ['protocolVersion' => '1999-01-01'])->assertJsonPath('result.protocolVersion', '2025-06-18');
        $this->mcp($key, 'ping')->assertOk()->assertExactJson(['jsonrpc' => '2.0', 'id' => 1, 'result' => []]);
    }

    public function test_every_tool_is_listed_read_only_with_an_object_schema(): void
    {
        [, $key] = $this->makeWorkspace();

        $response = $this->mcp($key, 'tools/list')->assertOk();
        $tools = $response->json('result.tools');

        $this->assertCount(count(ToolRegistry::TOOLS), $tools);
        $this->assertSame(count($tools), count(array_unique(array_column($tools, 'name'))), 'Tool names are unique.');

        foreach ($tools as $tool) {
            $this->assertMatchesRegularExpression('/^[a-z_]+$/', $tool['name']);
            $this->assertNotEmpty($tool['description']);
            $this->assertSame('object', $tool['inputSchema']['type']);
            $this->assertTrue($tool['annotations']['readOnlyHint']);
            $this->assertFalse($tool['annotations']['destructiveHint']);
        }

        // An argument-less tool still declares `properties` as a JSON object, not [].
        $this->assertStringContainsString('"name":"get_workspace","title":"Workspace overview"', $response->getContent());
        $this->assertMatchesRegularExpression('/"name":"get_workspace".*?"properties":\{\}/', $response->getContent());
    }

    public function test_only_a_live_server_key_opens_the_server(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $message = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'];

        $this->postJson('/mcp', $message)->assertUnauthorized()->assertHeader('WWW-Authenticate', 'Bearer realm="trigger-engage"');
        $this->postJson('/mcp', $message, ['Authorization' => 'Bearer te_wrong'])->assertUnauthorized();
        $this->postJson('/mcp', $message, ['Authorization' => 'Bearer '.$key])->assertOk();
        // The API's own Basic scheme works too.
        $this->postJson('/mcp', $message, $this->authHeaders($workspace, $key))->assertOk();

        // A publishable client key is public by design and never opens it.
        [$client] = ApiKey::issueClient($workspace, 'App');
        $this->postJson('/mcp', $message, ['Authorization' => 'Bearer '.$client->public_key])->assertUnauthorized();

        // Nor does a revoked key.
        [$revoked, $plaintext] = ApiKey::issue($workspace, 'old');
        $revoked->forceFill(['revoked_at' => now()])->save();
        $this->postJson('/mcp', $message, ['Authorization' => 'Bearer '.$plaintext])->assertUnauthorized();

        $this->assertNotNull(ApiKey::query()->where('workspace_id', $workspace->id)->where('kind', ApiKey::KIND_SERVER)->whereNull('revoked_at')->value('last_used_at'));
    }

    public function test_the_protocol_edges(): void
    {
        [, $key] = $this->makeWorkspace();
        $headers = ['Authorization' => 'Bearer '.$key];

        // Notifications are acknowledged with 202 and no body.
        $this->mcp($key, 'notifications/initialized', id: null)->assertStatus(202)->assertNoContent(202);

        // Batches (older clients) get an array back, notifications left out.
        $this->postJson('/mcp', [
            ['jsonrpc' => '2.0', 'id' => 'a', 'method' => 'ping'],
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ['jsonrpc' => '2.0', 'id' => 'b', 'method' => 'nope'],
        ], $headers)->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.id', 'a')
            ->assertJsonPath('1.error.code', -32601);

        $this->call('POST', '/mcp', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$key, 'CONTENT_TYPE' => 'application/json'], '{not json')
            ->assertStatus(400)->assertJsonPath('error.code', -32700);

        $this->mcp($key, 'resources/list')->assertJsonPath('error.code', -32601);

        $this->mcp($key, 'tools/call', ['name' => 'drop_tables'])
            ->assertJsonPath('error.code', -32602)
            ->assertJsonPath('error.data.available.0', 'get_workspace');

        // The transport is stateless: no stream, no session to end.
        $this->getJson('/mcp', $headers)->assertStatus(405)->assertHeader('Allow', 'POST');
        $this->deleteJson('/mcp', [], $headers)->assertStatus(405);
    }

    public function test_bad_arguments_come_back_as_tool_errors_the_model_can_read(): void
    {
        [, $key] = $this->makeWorkspace();

        $this->assertStringContainsString('get_person does not take: name', $this->toolError($key, 'get_person', ['name' => 'Ada']));
        $this->assertStringContainsString('external id', strtolower($this->toolError($key, 'get_person', [])));
        $this->assertStringContainsString('No person in this workspace has external_id "ghost"', $this->toolError($key, 'get_person', ['external_id' => 'ghost']));
        $this->assertStringContainsString('days', $this->toolError($key, 'get_analytics', ['days' => 3]));
        $this->assertStringContainsString('No automation with id 999', $this->toolError($key, 'get_automation', ['automation_id' => 999]));
    }

    public function test_a_key_only_reaches_its_own_workspace(): void
    {
        [$mine, $myKey] = $this->makeWorkspace();
        [$theirs] = $this->makeWorkspace();
        $template = $this->makeEmailTemplate($theirs);
        $channel = $this->makeLogEmailChannel($theirs);
        $automation = $this->makeAutomation($theirs, 'signed_up', $this->linearEmailGraph($template, $channel));
        $stranger = Person::create(['workspace_id' => $theirs->id, 'external_id' => 'user-1', 'email' => 'stranger@example.com']);
        Person::create(['workspace_id' => $mine->id, 'external_id' => 'user-2', 'email' => 'friend@example.com']);

        $this->assertStringContainsString('No person', $this->toolError($myKey, 'get_person', ['person_id' => $stranger->id]));
        $this->assertStringContainsString('No person', $this->toolError($myKey, 'get_person', ['external_id' => 'user-1']));
        $this->assertStringContainsString('No automation', $this->toolError($myKey, 'get_automation', ['automation_id' => $automation->id]));
        $this->assertStringContainsString('No template', $this->toolError($myKey, 'render_template', ['template_id' => $template->id]));
        $this->assertStringContainsString('No person', $this->toolError($myKey, 'diagnose_delivery', ['person_id' => $stranger->id, 'automation_id' => $automation->id]));

        $people = $this->tool($myKey, 'search_people')['items'];
        $this->assertSame(['user-2'], array_column($people, 'external_id'));
        $this->assertSame([], $this->tool($myKey, 'list_automations')['items']);
        $this->assertSame([], $this->tool($myKey, 'list_templates')['items']);
        $this->assertSame(1, $this->tool($myKey, 'get_workspace')['counts']['people']);
    }

    public function test_hosts_can_switch_tools_off(): void
    {
        [, $key] = $this->makeWorkspace();
        config(['trigger-engage-server.mcp.disabled_tools' => 'get_message, search_messages']);

        $names = array_column($this->mcp($key, 'tools/list')->json('result.tools'), 'name');

        $this->assertNotContains('get_message', $names);
        $this->assertNotContains('search_messages', $names);
        $this->assertContains('get_person', $names);
        $this->mcp($key, 'tools/call', ['name' => 'get_message', 'arguments' => ['message_id' => 1]])->assertJsonPath('error.code', -32602);
    }
}
