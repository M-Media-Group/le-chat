<?php

namespace Mmedia\LaravelChat\Traits;

use Illuminate\Contracts\Broadcasting\HasBroadcastChannel;
use Illuminate\Database\Eloquent\Casts\Attribute as CastsAttribute;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;

trait ConnectsToBroadcast
{
    public function getIsConnectedViaSockets(?string $channelName = null, string $type = 'private', ?string $localId = null): ?bool
    {
        if (! $channelName && $this instanceof HasBroadcastChannel) {
            $channelName = $this->broadcastChannel();
            Log::info('Using broadcast channel from HasBroadcastChannel: '.$channelName);
        } elseif (! $channelName) {
            throw new \InvalidArgumentException('Channel name must be provided or implement HasBroadcastChannel.');
        }

        if (! $localId) {
            $localId = $this->getKeyName();
        }

        // Throw an error if the type is not 'presence' or 'private'
        if (! in_array($type, ['presence', 'private'])) {
            throw new \InvalidArgumentException('The type must be either "presence" or "private".');
        }

        if (! in_array(Broadcast::getDefaultDriver(), ['pusher', 'reverb'])) {
            Log::warning('Broadcast driver is not pusher or reverb, cannot check participant connection status.');

            return null;
        }

        // If the broadcast driver is not pusher or reverb, set to null
        $instance = Broadcast::driver();
        if (! $instance) {
            throw new \RuntimeException('Broadcast driver is not set or is not supported.');
        }

        try {
            $rawPusher = $instance->getPusher();
            $isPrivate = $type === 'private';
            $channelPath = $isPrivate ? 'private-'.$channelName : 'presence-'.$channelName.'/users';
            $response = $rawPusher->get(
                '/channels/'.$channelPath,
                ['info' => 'users']
            );

            Log::info('Response from Reverb: '.json_encode($response, JSON_PRETTY_PRINT));

            if ($isPrivate) {
                return $response->occupied;
            }

            $users = $response->users;

            $isConnected = collect($users)->contains(function ($user) use ($localId) {
                return (int) $user->id === (int) $this->$localId;
            });
        } catch (\Exception $e) {
            // An exception may occur if the channel does not exist or the user is not connected, hence why we treat it as a non-connected state
            Log::error('Error checking participant connection status for channel '.$channelName.': '.$e->getMessage());
            // If the connection is not established, return false
            $isConnected = false;
        }

        return $isConnected;
    }

    protected function isConnected(): CastsAttribute
    {
        return CastsAttribute::make(
            get: fn () => $this->getIsConnectedViaSockets()
        )->shouldCache();
    }
}
