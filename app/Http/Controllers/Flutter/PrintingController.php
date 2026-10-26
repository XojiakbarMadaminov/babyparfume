<?php

namespace App\Http\Controllers\Flutter;

use App\Models\PrintJob;
use App\Models\PrintAgent;
use App\Models\PrintProfile;
use Illuminate\Http\Request;
use App\Models\PrintDocument;
use App\Models\PrintStoreSetting;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use App\Services\Printing\PrintAccess;
use Illuminate\Support\Facades\Storage;
use App\Services\Printing\PrintJobService;
use App\Services\Printing\PrintDocumentService;
use App\Http\Requests\Printing\ReprintJobRequest;
use App\Http\Requests\Printing\PrintDocumentRequest;
use App\Http\Requests\Printing\CreatePrintJobRequest;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PrintingController extends Controller
{
    public function options(Request $request): JsonResponse
    {
        $store   = $request->attributes->get('flutter_store');
        $user    = $request->user();
        $receipt = PrintAccess::allows($user, $store->id, 'print_receipts');
        $barcode = PrintAccess::allows($user, $store->id, 'print_barcodes');
        abort_unless($receipt || $barcode, 403);
        $agent    = PrintAgent::query()->where('active_store_id', $store->id)->first();
        $profiles = PrintProfile::query()->where('store_id', $store->id)->where('active', true)->whereNotNull('printer_id')
            ->whereIn('document_type', array_filter([$receipt ? 'receipt' : null, $barcode ? 'barcode' : null]))->with('printer')->get();

        return response()->json(['data' => ['enabled' => true, 'auto_print_receipt' => $receipt && (bool) PrintStoreSetting::query()->where('store_id', $store->id)->value('auto_print_receipt'),
            'agent_online'                            => $agent?->last_seen_at?->gt(now()->subSeconds(config('printing.heartbeat_timeout_seconds'))) ?? false,
            'can_reprint_receipts'                    => PrintAccess::allows($user, $store->id, 'reprint_receipts'),
            'profiles'                                => $profiles->map(fn (PrintProfile $p): array => ['id' => $p->id, 'version' => $p->version,
                'name'                                                                                       => $p->name, 'document_type' => $p->document_type, 'width_mm' => (float) $p->width_mm,
                'height_mm'                                                                                  => $p->height_mm === null ? null : (float) $p->height_mm, 'is_default' => $p->is_default,
                'selectable'                                                                                 => $p->selectable, 'default_copies' => $p->default_copies, 'max_copies' => $p->max_copies,
                'printer_name'                                                                               => $p->printer?->name])->all()]]);
    }

    public function preview(PrintDocumentRequest $request, PrintDocumentService $documents): JsonResponse
    {
        $made = $documents->create($request->user(), $request->attributes->get('flutter_store'), $request->attributes->get('flutter_device')->id, $request->validated());
        $d    = $made['document'];

        return response()->json(['data' => ['document_id' => $d->id, 'document_token' => $made['document_token'],
            'sha256'                                      => $d->sha256, 'copies' => $d->copies, 'total_labels' => $d->total_labels,
            'document_path'                               => '/stores/' . $d->store_id . '/printing/documents/' . $d->id,
            'expires_at'                                  => $d->expires_at->toIso8601String()]], 201);
    }

    public function document(Request $request, string $storeId, string $documentId): StreamedResponse
    {
        $d = PrintDocument::query()->where('store_id', $request->attributes->get('flutter_store')->id)->findOrFail($documentId);
        abort_unless($d->user_id === $request->user()->id || PrintAccess::allows($request->user(), $d->store_id, 'view_print_jobs'), 403);
        PrintAccess::authorize($request->user(), $d->store_id, $d->document_type === 'receipt' ? 'print_receipts' : 'print_barcodes');

        return Storage::disk('local')->response($d->path, 'preview.pdf', ['Content-Type' => 'application/pdf', 'Cache-Control' => 'no-store, private']);
    }

    public function create(CreatePrintJobRequest $request, PrintJobService $jobs): JsonResponse
    {
        $made = $jobs->create($request->user(), $request->attributes->get('flutter_store'), $request->attributes->get('flutter_device')->id, $request->validated());

        return response()->json(['data' => $jobs->present($made['job']), 'is_replay' => $made['replay']], $made['replay'] ? 200 : 201);
    }

    public function index(Request $request, PrintJobService $jobs): JsonResponse
    {
        $store = $request->attributes->get('flutter_store');
        $jobs->expire($store->id);
        $all   = PrintAccess::allows($request->user(), $store->id, 'view_print_jobs');
        $query = PrintJob::query()->where('store_id', $store->id)->when(!$all, fn ($q) => $q->where('user_id', $request->user()->id))
            ->with('document')->latest()->limit(50)->get();
        $allowed = $query->filter(fn ($job) => PrintAccess::allows(
            $request->user(),
            $store->id,
            $job->document->document_type === 'receipt' ? 'print_receipts' : 'print_barcodes'
        ));

        return response()->json(['data' => $allowed->map(fn ($job) => $jobs->present($job))->values()->all()]);
    }

    public function byClient(Request $request, string $storeId, string $clientId, PrintJobService $jobs): JsonResponse
    {
        $store = $request->attributes->get('flutter_store');
        $jobs->expire($store->id);
        $job = PrintJob::query()->where('store_id', $store->id)->where('user_id', $request->user()->id)
            ->where('client_job_id', $clientId)->firstOrFail();
        $jobs->authorizeJob($request->user(), $store, $job);

        return response()->json(['data' => $jobs->present($job)]);
    }

    public function show(Request $request, string $storeId, string $jobId, PrintJobService $jobs): JsonResponse
    {
        $store = $request->attributes->get('flutter_store');
        $jobs->expire($store->id);
        $job = PrintJob::query()->where('store_id', $store->id)->findOrFail($jobId);
        $jobs->authorizeJob($request->user(), $store, $job);

        return response()->json(['data' => $jobs->present($job)]);
    }

    public function cancel(Request $request, string $storeId, string $jobId, PrintJobService $jobs): JsonResponse
    {
        $store = $request->attributes->get('flutter_store');
        $job   = PrintJob::query()->where('store_id', $store->id)->findOrFail($jobId);
        $jobs->cancel($request->user(), $store, $job);

        return response()->json(['data' => $jobs->present($job->fresh())]);
    }

    public function reprint(ReprintJobRequest $request, string $storeId, string $jobId, PrintJobService $jobs): JsonResponse
    {
        $store    = $request->attributes->get('flutter_store');
        $original = PrintJob::query()->where('store_id', $store->id)->findOrFail($jobId);
        $made     = $jobs->create($request->user(), $store, $request->attributes->get('flutter_device')->id, $request->validated(), $original);

        return response()->json(['data' => $jobs->present($made['job']), 'is_replay' => $made['replay']], $made['replay'] ? 200 : 201);
    }
}
