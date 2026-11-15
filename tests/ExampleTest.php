<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Mmedia\LeChat\Http\Resources\ChatParticipantResource;
use Mmedia\LeChat\Models\ChatParticipant;
use Mmedia\LeChat\Models\Chatroom;

class ParticipantDisplayOwner extends Model
{
    protected $table = 'participant_display_owners';

    protected $guarded = [];

    public function getDisplayName(): string
    {
        return $this->name;
    }

    public function getAvatarUrl(): ?string
    {
        return $this->avatar_url;
    }
}

it('includes persisted display details in the participant resource', function () {
    Schema::create('participant_display_owners', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('avatar_url')->nullable();
        $table->timestamps();
    });

    $owner = ParticipantDisplayOwner::create([
        'name' => 'Model display name',
        'avatar_url' => 'https://example.test/model-avatar.png',
    ]);
    $room = Chatroom::create(['name' => 'Participant details']);
    $participant = ChatParticipant::create([
        'chatroom_id' => $room->getKey(),
        'participant_type' => $owner->getMorphClass(),
        'participant_id' => $owner->getKey(),
        'reference_id' => 'agent:display',
    ]);

    $payload = ChatParticipantResource::make($participant)->resolve();

    expect($payload['display_name'])->toBe('Model display name')
        ->and($payload['avatar_url'])->toBe('https://example.test/model-avatar.png');

    $participant->update([
        'display_name' => 'Room-specific name',
        'avatar_url' => 'https://example.test/room-avatar.png',
    ]);
    $payload = ChatParticipantResource::make($participant)->resolve();

    expect($payload['display_name'])->toBe('Room-specific name')
        ->and($payload['avatar_url'])->toBe('https://example.test/room-avatar.png');
});
