<?php

namespace Mmedia\LeChat\Traits;

use Illuminate\Contracts\Broadcasting\HasBroadcastChannel;
use Illuminate\Database\Eloquent\Casts\Attribute as CastsAttribute;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;

/**
 * Trait ConnectsToBroadcast
 *
 * This trait provides methods to check if a model is connected to a broadcast channel via sockets.
 *
 * It supports both private and presence channels.
 *
 * @template M of \Illuminate\Database\Eloquent\Model
 *
 * @mixin M
 */
trait ConnectsToBroadcast
{
    /**
     * Determine if the model is connected to the broadcast channel via sockets.
     *
     * @param  string|null  $channelName  the name of the broadcast channel. By default, it will use the channel name from the HasBroadcastChannel trait.
     * @param  string  $type  the type of channel, either 'private' or 'presence'.
     * @param  string|null  $localId  the local ID of the model. If not provided, it will use the model's primary key.
     *
     * @throws \InvalidArgumentException if the channel name is not provided and the model does not implement HasBroadcastChannel.
     * @throws \InvalidArgumentException if the type is not 'private' or 'presence'.
     * @throws \RuntimeException if the broadcast driver is not set or is not supported.
     * @throws \Exception if there is an error checking the participant connection status.
     */
    public function isConnectedViaSockets(?string $channelName = null, string $type = 'private', ?string $localId = null): ?bool
    {
        if (! $channelName && $this instanceof HasBroadcastChannel) {
            $channelName = $this->broadcastChannel();
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

    /**
     * A convenience attribute to check if the participant is connected via sockets. E.g.: $model->is_connected
     */
    protected function isConnected(): CastsAttribute
    {
        return CastsAttribute::make(
            get: fn () => $this->isConnectedViaSockets()
        )->shouldCache();
    }
}
