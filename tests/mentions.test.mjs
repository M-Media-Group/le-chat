import assert from 'node:assert/strict';
import test from 'node:test';
import { mentionSegments, mentionSuggestions, mentionToken, renderMentions } from '../resources/js/mentions.mjs';

const participants = [
    { id: 12, participant_id: 1, display_name: 'Tour Guide', mention_token: '<@12>', deleted_at: null },
    { id: 24, display_name: 'Guide AI', mention_token: '<@24>', deleted_at: null },
    { id: 36, display_name: 'Former Guide', deleted_at: '2026-11-16T00:00:00Z' },
];

test('mention suggestions insert the participant token rather than the underlying user ID', () => {
    assert.deepEqual(mentionSuggestions(participants, '@tour'), [
        { id: 12, label: '@Tour Guide', token: '<@12>' },
    ]);
    assert.equal(mentionSuggestions(participants).length, 2);
    assert.throws(() => mentionToken({ id: '12><script>' }), TypeError);
});

test('resolves known active IDs and leaves foreign, removed, and model IDs unchanged', () => {
    const segments = mentionSegments('Hi <@12>, <@999>, <@36>, <@1> and <@24>', participants);

    assert.equal(segments.map((segment) => segment.text).join(''), 'Hi @Tour Guide, <@999>, <@36>, <@1> and @Guide AI');
    assert.deepEqual(segments.filter((segment) => segment.type === 'mention').map((segment) => segment.participantId), ['12', '24']);
});

test('keeps canonical identity stable when display names change or are duplicated', () => {
    const renamed = participants.map((participant) => ({ ...participant, display_name: 'Same Name' }));
    const segments = mentionSegments('<@24><@12>', renamed);

    assert.deepEqual(segments.map((segment) => segment.participantId), ['24', '12']);
    assert.deepEqual(segments.map((segment) => segment.text), ['@Same Name', '@Same Name']);
});

test('renders names and arbitrary message HTML only through text nodes and textContent', () => {
    const container = {
        ownerDocument: {
            createTextNode: (text) => ({ nodeType: 'text', textContent: text }),
            createElement: (tag) => ({ tag, dataset: {} }),
        },
        replaceChildren(...nodes) {
            this.nodes = nodes;
        },
    };
    const untrusted = [{ id: 12, display_name: '<img src=x onerror=alert(1)>' }];

    renderMentions(container, '<script>alert(1)</script><@12>', untrusted);

    assert.deepEqual(container.nodes, [
        { nodeType: 'text', textContent: '<script>alert(1)</script>' },
        { tag: 'span', dataset: { participantId: '12' }, className: 'chat-mention', textContent: '@<img src=x onerror=alert(1)>' },
    ]);
});