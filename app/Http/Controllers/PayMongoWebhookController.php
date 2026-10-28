<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessPayMongoWebhook;
use App\Models\PayMongoWebhookInbox;
use App\Services\PayMongo\VerifyPayMongoWebhook;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PayMongoWebhookController extends Controller
{
    public function __invoke(Request $request, VerifyPayMongoWebhook $verifier): JsonResponse
    {
        $event = $verifier->handle($request);

        if ($event === null) {
            return response()->json(['message' => 'Invalid webhook.'], 400);
        }

        try {
            $inbox = DB::transaction(fn (): PayMongoWebhookInbox => PayMongoWebhookInbox::query()->create([
                ...$event,
                'received_at' => now(),
                'next_check_at' => now(),
            ]));
        } catch (QueryException $exception) {
            if (PayMongoWebhookInbox::query()->where('mode', $event['mode'])->where('event_id', $event['event_id'])->exists()) {
                return response()->json(['received' => true]);
            }

            throw $exception;
        }

        ProcessPayMongoWebhook::dispatch($inbox->id)->onQueue('payments')->afterCommit();

        return response()->json(['received' => true], 202);
    }
}
