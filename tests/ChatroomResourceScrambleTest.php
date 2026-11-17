<?php

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;

it('exports typed schemas for the chat API', function () {
    $path = tempnam(sys_get_temp_dir(), 'le-chat-openapi-');
    if ($path === false) {
        throw new RuntimeException('Unable to create a temporary OpenAPI export path.');
    }

    try {
        $output = new BufferedOutput;
        $options = ['--path' => $path];
        $exportCommand = Artisan::all()['scramble:export'];
        if ($exportCommand->getDefinition()->hasOption('fail-on-unknown')) {
            $options['--fail-on-unknown'] = true;
        }
        $exitCode = Artisan::call('scramble:export', $options, $output);
        if ($exitCode !== 0) {
            throw new RuntimeException($output->fetch());
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Unable to read the temporary OpenAPI export.');
        }
        $spec = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } finally {
        unlink($path);
    }

    $schemas = $spec['components']['schemas'];
    $chatroomListSchema = $spec['paths']['/chatrooms']['get']['responses']['200']['content']['application/json']['schema'];
    $chatroomListItems = $chatroomListSchema['items']
        ?? $chatroomListSchema['properties']['data']['items']
        ?? null;
    $chatroomListItemReference = $chatroomListItems['$ref'] ?? $chatroomListItems['allOf'][0]['$ref'] ?? null;
    $chatroomListItemRequired = $chatroomListItems['allOf'][1]['required'] ?? [];

    expect($spec['paths'])->toHaveKeys([
        '/chatrooms',
        '/chatrooms/{chatroom}',
        '/approvals/{batch}',
        '/interactions',
        '/messages',
    ])
        ->and($chatroomListItemReference)
        ->toBe('#/components/schemas/ChatroomResource')
        ->and($chatroomListItemRequired)
        ->toContain('participants')
        ->and($schemas['ChatroomResource']['properties']['messages']['items']['$ref'])
        ->toBe('#/components/schemas/MessageResource')
        ->and($schemas['ChatroomResource']['properties']['participants']['items']['$ref'])
        ->toBe('#/components/schemas/ChatParticipantResource')
        ->and($schemas['ChatParticipantResource']['properties']['mention_aliases']['items']['type'])
        ->toBe('string')
        ->and($schemas['MessageResource']['required'])
        ->not->toContain('replies')
        ->and($schemas['ChatParticipantResource']['properties']['ai_response_mode']['enum'])
        ->toBe(['all', 'mentions', 'off'])
        ->and($schemas['ChatParticipantResource']['required'])
        ->not->toContain('ai_response_mode')
        ->and($schemas['ChatroomResource']['properties'])
        ->not->toHaveKey('can_see_messages_before_joined')
        ->and($schemas['ChatParticipantResource']['properties'])
        ->not->toHaveKey('can_see_messages_before_joined')
        ->not->toHaveKey('metadata');

    $approvalSchema = $spec['paths']['/approvals/{batch}']['get']['responses']['200']['content']['application/json']['schema'];
    $decisionSchema = $spec['paths']['/approvals/{batch}']['post']['requestBody']['content']['application/json']['schema']['properties']['decisions'];
    $interactionValueSchema = $spec['paths']['/interactions']['post']['requestBody']['content']['application/json']['schema']['properties']['value'];
    $interactionValueTypes = $interactionValueSchema['type']
        ?? collect($interactionValueSchema['anyOf'] ?? [])->pluck('type')->all();
    $interactionValueTypes = collect($interactionValueTypes)
        ->map(fn (string $type): string => $type === 'integer' ? 'number' : $type)
        ->unique()
        ->values()
        ->all();
    $sendMessageErrorSchema = $spec['paths']['/messages']['post']['responses']['400']['content']['application/json']['schema'];

    expect($approvalSchema['properties']['id']['type'])->toBe('integer')
        ->and($approvalSchema['properties']['status']['type'])->toBe('string')
        ->and($approvalSchema['properties']['approvals']['items']['properties']['arguments']['type'])
        ->toBe('object')
        ->and($decisionSchema['items']['properties']['action']['enum'])
        ->toBe(['approve', 'reject'])
        ->and($interactionValueTypes)
        ->toBe(['string', 'number', 'boolean', 'array', 'object', 'null'])
        ->and($sendMessageErrorSchema['properties']['message']['type'])
        ->toBe('string');
});
