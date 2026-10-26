<?php

namespace App\Http\Controllers\Printing;

use App\Models\PrintJob;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use App\Services\Printing\PrintJobService;
use App\Services\Printing\PrintAgentService;
use App\Http\Requests\Printing\PairAgentRequest;
use App\Http\Requests\Printing\AgentEventRequest;
use App\Http\Requests\Printing\AgentHeartbeatRequest;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AgentController extends Controller
{
    public function pair(PairAgentRequest $request, PrintAgentService $agents): JsonResponse
    {
        $data = $request->validated();

        return response()->json(['data' => $agents->pair($data['code'], $data['name'], $data['version'])], 201);
    }

    public function heartbeat(AgentHeartbeatRequest $request, PrintAgentService $agents): JsonResponse
    {
        return response()->json(['data' => $agents->heartbeat($request->attributes->get('print_agent'), $request->validated())]);
    }

    public function claim(Request $request, PrintJobService $jobs): JsonResponse
    {
        $job = $jobs->claim($request->attributes->get('print_agent'));

        return response()->json(['data' => $job ? [...$jobs->present($job), 'claim_token' => $job->claim_token,
            'server_time' => now()->toIso8601String(), 'sha256' => $job->document->sha256, 'document_path' => 'jobs/' . $job->id . '/document',
            'profile'     => $job->document->profile_snapshot] : null]);
    }

    public function show(Request $request, string $jobId, PrintJobService $jobs): JsonResponse
    {
        $job = PrintJob::query()->where('agent_id', $request->attributes->get('print_agent')->id)->findOrFail($jobId);

        return response()->json(['data' => [...$jobs->present($job), 'claim_token' => $job->claim_token]]);
    }

    public function document(Request $request, string $jobId): StreamedResponse
    {
        $job = PrintJob::query()->where('agent_id', $request->attributes->get('print_agent')->id)->findOrFail($jobId);
        abort_unless(in_array($job->status, ['claimed', 'rendering', 'submitting', 'spooled', 'unknown'], true), 409);

        return Storage::disk('local')->response($job->document->path, 'print.pdf', ['Content-Type' => 'application/pdf', 'Cache-Control' => 'no-store, private']);
    }

    public function event(AgentEventRequest $request, string $jobId, PrintJobService $jobs): JsonResponse
    {
        return response()->json(['data' => $jobs->present($jobs->event($request->attributes->get('print_agent'), $jobId, $request->validated()))]);
    }
}
