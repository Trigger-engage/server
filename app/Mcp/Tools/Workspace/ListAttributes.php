<?php

namespace TriggerEngage\Server\Mcp\Tools\Workspace;

use TriggerEngage\Server\Mcp\Context;
use TriggerEngage\Server\Mcp\Support\KeySampler;
use TriggerEngage\Server\Mcp\Tool;
use TriggerEngage\Server\Models\Person;

class ListAttributes extends Tool
{
    public function name(): string
    {
        return 'list_attributes';
    }

    public function title(): string
    {
        return 'List profile attributes';
    }

    public function description(): string
    {
        return 'The attribute keys people\'s profiles carry, with their types, how often they are filled and an example '
            .'(contact details masked). Use these exact names in segment rules ({"kind":"attribute","field":"<key>"}) '
            .'and in templates ({{ person.<key> }}). email, phone and external_id are columns, not attributes, and '
            .'are counted across the whole workspace; attribute keys come from the most recently updated profiles.';
    }

    public function properties(): array
    {
        return [
            'sample_size' => ['type' => 'integer', 'minimum' => 50, 'maximum' => 2000, 'description' => 'How many recently updated profiles to read (default 500).'],
            'search' => ['type' => 'string', 'description' => 'Only keys containing this text.'],
        ];
    }

    public function rules(): array
    {
        return [
            'sample_size' => ['nullable', 'integer', 'min:50', 'max:2000'],
            'search' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function handle(array $arguments, Context $context): array
    {
        $people = fn () => Person::query()->where('workspace_id', $context->workspace->id);
        $total = $people()->count();
        $sampleSize = (int) ($arguments['sample_size'] ?? 500);

        $sampler = new KeySampler($context->redactor);
        $people()->orderByDesc('updated_at')->limit($sampleSize)->get(['id', 'attributes'])
            ->each(fn (Person $person) => $sampler->add($person->properties()));

        $keys = collect($sampler->keys());
        if (filled($arguments['search'] ?? null)) {
            $keys = $keys->filter(fn (array $key) => str_contains(strtolower($key['key']), strtolower($arguments['search'])));
        }

        $filled = fn (string $column) => $people()->whereNotNull($column)->where($column, '!=', '')->count();

        return [
            'people' => $total,
            'columns' => [
                ['key' => 'external_id', 'filled' => $filled('external_id')],
                ['key' => 'email', 'filled' => $filled('email')],
                ['key' => 'phone', 'filled' => $filled('phone')],
            ],
            'attributes_sampled_from' => $sampler->count(),
            'attributes' => $keys->values()->all(),
        ];
    }
}
