function activeParticipants(participants) {
    return participants.filter((participant) => participant.deleted_at == null);
}

export function mentionToken(participant) {
    const token = participant.mention_token ?? `<@${participant.id}>`;
    if (!/^<@[1-9][0-9]*>$/.test(token)) {
        throw new TypeError('A mention requires a canonical participant token.');
    }

    return token;
}

export function mentionSuggestions(participants, query = '') {
    const search = query.replace(/^@/, '').toLocaleLowerCase();

    return activeParticipants(participants)
        .filter((participant) => (participant.display_name ?? '').toLocaleLowerCase().includes(search))
        .map((participant) => ({
            id: participant.id,
            label: `@${participant.display_name || 'Participant'}`,
            token: mentionToken(participant),
        }));
}

export function mentionSegments(text, participants) {
    const byToken = new Map(activeParticipants(participants).map((participant) => [mentionToken(participant), participant]));
    const segments = [];
    let position = 0;

    for (const match of text.matchAll(/<@([1-9][0-9]*)>/g)) {
        const participant = byToken.get(match[0]);
        if (!participant) {
            continue;
        }
        if (match.index > position) {
            segments.push({ type: 'text', text: text.slice(position, match.index) });
        }
        segments.push({
            type: 'mention',
            text: `@${participant.display_name || 'Participant'}`,
            participantId: match[1],
            token: match[0],
        });
        position = match.index + match[0].length;
    }
    if (position < text.length) {
        segments.push({ type: 'text', text: text.slice(position) });
    }

    return segments;
}

export function renderMentions(container, text, participants) {
    const document = container.ownerDocument;
    const nodes = mentionSegments(text, participants).map((segment) => {
        if (segment.type === 'text') {
            return document.createTextNode(segment.text);
        }
        const mention = document.createElement('span');
        mention.className = 'chat-mention';
        mention.dataset.participantId = segment.participantId;
        mention.textContent = segment.text;

        return mention;
    });
    container.replaceChildren(...nodes);

    return container;
}