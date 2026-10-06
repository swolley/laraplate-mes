<?php

declare(strict_types=1);

namespace Modules\MES\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;
use Modules\MES\Enums\MachineTransport;
use Modules\MES\Http\Middleware\AuthenticateMachineSource;
use Modules\MES\Machine\MachineMessageInbox;
use Modules\MES\Machine\Protocol\MachineEnvelopeValidator;
use Modules\MES\Models\MachineSource;

/**
 * Receives `laraplate-machine/1` envelopes from the machine sources. It validates the envelope
 * and hands the raw body to the inbox; everything else is asynchronous.
 */
final class MachineDataIngestController extends Controller
{
    public function __invoke(Request $request, MachineEnvelopeValidator $validator, MachineMessageInbox $inbox): JsonResponse
    {
        $source = $request->attributes->get(AuthenticateMachineSource::REQUEST_ATTRIBUTE);

        if (! $source instanceof MachineSource) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($source->transport !== MachineTransport::Http || $source->normalizer !== 'canonical') {
            return response()->json(['message' => 'This source is not configured for HTTP envelopes.'], 403);
        }

        $body = $request->getContent();

        if (strlen($body) > config()->integer('mes.machine.max_body_kb') * 1024) {
            return response()->json(['message' => 'The body is too large; split the batch.'], 413);
        }

        try {
            $envelope = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return response()->json(['message' => 'The body is not valid JSON.', 'errors' => ['body' => ['The body is not valid JSON.']]], 422);
        }

        if (! is_array($envelope)) {
            return response()->json(['message' => 'The envelope is invalid.', 'errors' => ['body' => ['The body is not a JSON object.']]], 422);
        }

        $errors = $validator->validate($envelope);

        if ($errors !== []) {
            return response()->json(['message' => 'The envelope is invalid.', 'errors' => $errors], 422);
        }

        if ($validator->sampleCount($envelope) > config()->integer('mes.machine.max_samples')) {
            return response()->json(['message' => 'Too many samples; split the batch.'], 413);
        }

        $result = $inbox->accept($source, $body, MachineTransport::Http);

        return $result->duplicate
            ? response()->json(['status' => 'duplicate', 'duplicate' => true, 'message_id' => $result->message->message_id])
            : response()->json(['status' => 'accepted', 'message_id' => $result->message->message_id], 202);
    }
}
