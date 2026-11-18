<?php

namespace Tests\Concerns;

use Illuminate\Testing\TestResponse;

trait CallsMcp
{
    protected function mcp(string $key, string $method, array $params = [], int|string|null $id = 1): TestResponse
    {
        $message = ['jsonrpc' => '2.0', 'method' => $method, 'params' => (object) $params];
        if ($id !== null) {
            $message['id'] = $id;
        }

        return $this->postJson('/mcp', $message, ['Authorization' => 'Bearer '.$key]);
    }

    /** The structured result of a tool call that must succeed. */
    protected function tool(string $key, string $name, array $arguments = []): array
    {
        $response = $this->mcp($key, 'tools/call', ['name' => $name, 'arguments' => (object) $arguments])->assertOk();
        $result = $response->json('result');

        $this->assertNotNull($result, 'tools/call returned no result: '.$response->getContent());
        $this->assertFalse($result['isError'], $name.' failed: '.($result['content'][0]['text'] ?? ''));
        $this->assertSame($result['structuredContent'], json_decode($result['content'][0]['text'], true), 'The text content mirrors the structured content.');

        return $result['structuredContent'];
    }

    /** The message of a tool call that must come back as a tool error. */
    protected function toolError(string $key, string $name, array $arguments = []): string
    {
        $result = $this->mcp($key, 'tools/call', ['name' => $name, 'arguments' => (object) $arguments])->assertOk()->json('result');

        $this->assertTrue($result['isError'] ?? false, $name.' was expected to fail but returned: '.json_encode($result));

        return $result['content'][0]['text'];
    }
}
