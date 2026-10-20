<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class IdempotencyKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = trim((string) $request->header('Idempotency-Key'));
        if ($key === '') {
            return $next($request);
        }
        if (! preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $key)) {
            return response()->json(['message' => 'Idempotency-Key harus 8-100 karakter aman.'], 422);
        }

        $user = $request->user();
        if (! $user) {
            return $next($request);
        }
        $hash = hash('sha256', $request->getContent());
        $identity = ['company_id' => $user->company_id, 'key' => $key];
        $record = DB::table('api_idempotency_keys')->where($identity)->first();

        if ($record) {
            return $this->replayOrConflict($record, $request, $hash);
        }

        try {
            $id = DB::table('api_idempotency_keys')->insertGetId([
                ...$identity,
                'user_id' => $user->id,
                'method' => $request->method(),
                'path' => $request->path(),
                'request_hash' => $hash,
                'status' => 'processing',
                'expires_at' => now()->addDay(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $error) {
            $record = DB::table('api_idempotency_keys')->where($identity)->first();

            // Only a real company/key collision is an idempotent replay race.
            // Schema, connectivity, or datatype errors must remain visible;
            // turning them into a 409 masks infrastructure defects.
            if (! $record) {
                throw $error;
            }

            return $this->replayOrConflict($record, $request, $hash);
        }

        try {
            $response = $next($request);
            if ($response->getStatusCode() < 500) {
                DB::table('api_idempotency_keys')->where('id', $id)->update([
                    'status' => 'completed',
                    'response_status' => $response->getStatusCode(),
                    'response_body' => $response->getContent(),
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('api_idempotency_keys')->where('id', $id)->delete();
            }

            return $response;
        } catch (\Throwable $error) {
            DB::table('api_idempotency_keys')->where('id', $id)->delete();
            throw $error;
        }
    }

    private function replayOrConflict(?object $record, Request $request, string $hash): Response
    {
        if (! $record || $record->request_hash !== $hash || $record->method !== $request->method() || $record->path !== $request->path()) {
            return response()->json(['message' => 'Idempotency-Key sudah digunakan untuk permintaan berbeda.'], 409);
        }
        if ($record->status !== 'completed') {
            return response()->json(['message' => 'Permintaan dengan Idempotency-Key ini masih diproses.'], 409, ['Retry-After' => '2']);
        }

        return new JsonResponse(
            json_decode($record->response_body, true),
            $record->response_status,
            ['Idempotency-Replayed' => 'true']
        );
    }
}
