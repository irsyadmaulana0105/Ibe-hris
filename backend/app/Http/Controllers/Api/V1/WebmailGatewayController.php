<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\WebmailGatewaySessionService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

class WebmailGatewayController extends ApiController
{
    public function __construct(private readonly WebmailGatewaySessionService $sessions) {}

    public function exchange(Request $request)
    {
        $data = $request->validate(['ticket' => ['required', 'string', 'size:64']]);
        [$plainSession, $user] = $this->sessions->redeem($data['ticket'], $request);
        $cookie = Cookie::create(config('webmail_gateway.cookie'), $plainSession)
            ->withExpires(now()->addMinutes(config('webmail_gateway.session_ttl_minutes')))
            ->withPath('/')->withSecure(true)->withHttpOnly(true)->withSameSite('lax');

        app(\App\Services\AuditLogService::class)->log(
            'webmail.gateway_session_created', $user, 'mailbox', $user->id,
            ['email' => $user->email], $request,
        );

        return redirect('/', 303)->withCookie($cookie)
            ->header('Cache-Control', 'no-store');
    }

    public function session(Request $request)
    {
        $session = $this->sessions->resolve($request);
        abort_unless($session, 401, 'Webmail session tidak aktif.');
        return response()->json(['data' => [
            'email' => $session->mailbox_email,
            'mailboxes' => $this->sessions->availableMailboxes($session),
            'expires_at' => $session->expires_at,
        ]])->header('Cache-Control', 'no-store');
    }

    public function switchMailbox(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:255']]);
        $session = $this->sessions->switchMailbox($request, $data['email']);

        return response()->json(['data' => [
            'email' => $session->mailbox_email,
            'mailboxes' => $this->sessions->availableMailboxes($session),
        ]])->header('Cache-Control', 'no-store');
    }

    public function authorizeUpstream(Request $request)
    {
        $expected = (string) config('webmail_gateway.internal_secret');
        $provided = (string) $request->header('X-IBE-Internal-Secret');
        abort_unless($expected !== '' && hash_equals($expected, $provided), 404);
        $session = $this->sessions->resolve($request);
        abort_unless($session, 401);

        return response('', 204)
            ->header('X-IBE-Upstream-Authorization', $this->sessions->upstreamAuthorization($session))
            ->header('X-IBE-Mailbox', $session->mailbox_email)
            ->header('Cache-Control', 'no-store');
    }

    public function logout(Request $request)
    {
        $this->sessions->revoke($request);
        return response()->noContent()->withCookie(Cookie::create(config('webmail_gateway.cookie'))
            ->withValue('')->withExpires(1)->withPath('/')->withSecure(true)->withHttpOnly(true)->withSameSite('lax'));
    }
}
