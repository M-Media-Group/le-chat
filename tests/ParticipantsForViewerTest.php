<?php

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Schema;
use Mmedia\LeChat\Contracts\ChatParticipantInterface;
use Mmedia\LeChat\Models\Chatroom;
use Mmedia\LeChat\Traits\IsChatParticipant;

/** @implements ChatParticipantInterface<ViewerTestUser> */
class ViewerTestUser extends Authenticatable implements ChatParticipantInterface
{
    use IsChatParticipant;

    protected $table = 'viewer_test_users';

    protected $guarded = [];
}

beforeEach(function () {
    Schema::create('viewer_test_users', function (Blueprint $table): void {
        $table->id();
        $table->timestamps();
    });
    config(['chat.create_system_messages' => false]);
    $this->withoutMiddleware(Authenticate::class);

    [$this->ana, $this->ben, $this->cara, $this->dan] = [
        ViewerTestUser::create(), ViewerTestUser::create(), ViewerTestUser::create(), ViewerTestUser::create(),
    ];

    // Everyone left this room, Ana included.
    $this->emptied = Chatroom::create(['name' => 'Emptied']);
    $this->emptied->addParticipants([$this->ana, $this->ben]);
    $this->emptied->removeParticipants([$this->ana, $this->ben]);

    // Ana is still here; Dan left.
    $this->active = Chatroom::create(['name' => 'Active']);
    $this->active->addParticipants([$this->ana, $this->cara, $this->dan]);
    $this->active->removeParticipant($this->dan);
});

/** @return array<string, string|null> participant_id => deleted_at */
function participantsById(array $participants): array
{
    return collect($participants)->mapWithKeys(fn (array $p) => [$p['participant_id'] => $p['deleted_at']])->sortKeys()->all();
}

it('lists current participants plus the viewer when they left, in the chatroom list', function () {
    $rooms = collect($this->actingAs($this->ana)->getJson('/api/chatrooms')->assertOk()->json('data'))->keyBy('name');

    $emptied = participantsById($rooms['Emptied']['participants']);
    expect(array_keys($emptied))->toBe([$this->ana->id])
        ->and($emptied[$this->ana->id])->not->toBeNull()
        ->and(participantsById($rooms['Active']['participants']))->toBe([$this->ana->id => null, $this->cara->id => null]);
});

it('includes the viewer who left when showing a chatroom', function () {
    $participants = $this->actingAs($this->ana)->getJson('/api/chatrooms/'.$this->emptied->id)
        ->assertOk()->json('data.participants');

    expect(array_keys(participantsById($participants)))->toBe([$this->ana->id])
        ->and($participants[0]['deleted_at'])->not->toBeNull();
});

it('leaves the viewer out of rooms they were never in', function () {
    $room = Chatroom::query()->withParticipantsFor($this->cara)->findOrFail($this->emptied->id);

    expect($room->participants)->toBeEmpty()
        ->and($this->active->loadParticipantsFor($this->dan)->participants->pluck('participant_id')->sort()->values()->all())
        ->toBe([$this->ana->id, $this->cara->id, $this->dan->id])
        ->and($this->active->participants->firstWhere('participant_id', $this->dan->id)->trashed())->toBeTrue();
});
