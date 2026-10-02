<?php

namespace App\Broadcasting;

use Illuminate\Broadcasting\Broadcasters\LogBroadcaster;
use Illuminate\Broadcasting\Broadcasters\UsePusherChannelConventions;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Log-based broadcaster that still evaluates private/presence channel
 * authorization locally instead of returning an empty 200.
 *
 * In automated test runs we force BROADCAST_CONNECTION=log so events never
 * reach a real Reverb/Pusher socket server. The default LogBroadcaster,
 * however, treats /broadcasting/auth as a no-op and responds with an empty
 * 200 for every channel. This subclass keeps the socket-free "log" behaviour
 * for actual broadcasts while running the same in-process channel callbacks
 * (routes/channels.php) the production broadcasters rely on, so access
 * control can be asserted deterministically under tests.
 */
class AuthorizableLogBroadcaster extends LogBroadcaster
{
    use UsePusherChannelConventions;

    /**
     * Authenticate the incoming request for a given channel.
     *
     * @param  Request  $request
     * @return mixed
     *
     * @throws AccessDeniedHttpException
     */
    public function auth($request)
    {
        $channelName = $this->normalizeChannelName($request->channel_name);

        if (empty($request->channel_name) || ($this->isGuardedChannel($request->channel_name) && ! $this->retrieveUser($request, $channelName))) {
            throw new AccessDeniedHttpException;
        }

        return parent::verifyUserCanAccessChannel($request, $channelName);
    }

    /**
     * Return the valid authentication response for an authorized channel,
     * shaped like the Pusher-style {@see ["auth" => string]} payload the
     * clinic SPA expects.
     *
     * @param  Request  $request
     * @param  mixed  $result
     * @return array<string, string>
     */
    public function validAuthenticationResponse($request, $result): array
    {
        $channelName = $this->normalizeChannelName($request->channel_name);

        $user = $this->retrieveUser($request, $channelName);

        $broadcastIdentifier = method_exists($user, 'getAuthIdentifierForBroadcasting')
            ? $user->getAuthIdentifierForBroadcasting()
            : $user->getAuthIdentifier();

        $signature = base64_encode(implode(':', [
            $request->socket_id,
            $request->channel_name,
            (string) $broadcastIdentifier,
        ]));

        if (str_starts_with($request->channel_name, 'presence')) {
            return [
                'auth' => $signature,
                'channel_data' => json_encode([
                    'user_id' => $broadcastIdentifier,
                    'user_info' => $result,
                ]),
            ];
        }

        return ['auth' => $signature];
    }
}
