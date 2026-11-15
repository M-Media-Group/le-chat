<?php

namespace Mmedia\LeChat\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** @internal Block validation and fallback generation for chat messages. */
class MessageBlocks
{
    public static function validate(array $blocks): array
    {
        $rules = [
            'blocks' => ['array', 'min:1', 'max:'.config('chat.blocks.max_blocks', 50)],
            'blocks.*' => ['required', 'array:schema_version,type,block_id,text,elements'],
            'blocks.*.schema_version' => ['required', 'integer', Rule::in([1])],
            'blocks.*.type' => ['required', Rule::in(['section', 'actions'])],
            'blocks.*.block_id' => ['required', 'string', 'max:255', 'distinct'],
            'blocks.*.elements.*' => ['required', 'array:type,action_id,text'],
            'blocks.*.elements.*.type' => ['required', Rule::in(['button'])],
            'blocks.*.elements.*.action_id' => ['required', 'string', 'max:255'],
            'blocks.*.elements.*.text' => ['required', 'string', 'max:'.config('chat.blocks.max_text_length', 3000)],
        ];

        foreach ($blocks as $index => $block) {
            if (! is_int($index) || ! is_array($block)) {
                continue;
            }

            if (($block['type'] ?? null) === 'section') {
                $rules["blocks.{$index}.text"] = ['required', 'string', 'max:'.config('chat.blocks.max_text_length', 3000)];
            } elseif (($block['type'] ?? null) === 'actions') {
                $rules["blocks.{$index}.elements"] = ['required', 'array', 'min:1', 'max:50'];
            }
        }

        Validator::make(['blocks' => $blocks], $rules)->validate();

        if (! array_is_list($blocks) || strlen(json_encode($blocks, JSON_THROW_ON_ERROR)) > config('chat.blocks.max_payload_bytes', 65536)) {
            throw ValidationException::withMessages(['blocks' => 'Blocks must be a list within the configured payload size limit.']);
        }

        foreach ($blocks as $block) {
            if (($block['type'] === 'section' && array_key_exists('elements', $block)) ||
                ($block['type'] === 'actions' && (array_key_exists('text', $block) || ! array_is_list($block['elements'])))) {
                throw ValidationException::withMessages(['blocks' => 'The block contains fields invalid for its type.']);
            }
            if ($block['type'] === 'actions') {
                $ids = array_column($block['elements'], 'action_id');
                if (count($ids) !== count(array_unique($ids))) {
                    throw ValidationException::withMessages(['blocks' => 'Action IDs must be unique within a block.']);
                }
            }
        }

        return $blocks;
    }

    public static function fallback(array $blocks): string
    {
        $parts = [];
        foreach ($blocks as $block) {
            if ($block['type'] === 'section') {
                $parts[] = $block['text'];
            } else {
                foreach ($block['elements'] as $element) {
                    $parts[] = $element['text'];
                }
            }
        }

        return implode("\n", $parts);
    }
}
