<?php

namespace App\Http\Controllers\Administration;

use App\Actions\Inventory\HandleCustomerReturn;
use App\Actions\Inventory\HandleCustomerReturnRequest;
use App\Http\Controllers\Controller;
use App\Models\OrderItem;
use App\Services\AdministrationEvidenceService;
use App\Services\CustomerReturnReviewService;
use App\Support\OperationalRequestReason;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CustomerReturnController extends Controller
{
    public function index(Request $request, CustomerReturnReviewService $review): Response
    {
        $canReceive = app(AdministrationEvidenceService::class)->canReceiveCustomerReturn($request->user());
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'overdue' => ['nullable', 'boolean']]);
        $case = $request->validate(['case' => ['nullable', 'integer', 'min:1']])['case'] ?? null;
        $requestId = $request->validate(['request_id' => ['nullable', 'integer', 'min:1']])['request_id'] ?? null;
        $paymentId = $request->validate(['payment_id' => ['nullable', 'integer', 'min:1']])['payment_id'] ?? null;
        $case = $review->resolveCase($case, $paymentId);
        $orderLookup = $request->validate(['order_id' => ['nullable', 'string', 'max:32']])['order_id'] ?? null;

        return Inertia::render('Administration/CustomerReturns', $review->page($request->user(), $filters, $case, $requestId, $paymentId, $orderLookup, $request->boolean('overdue'), $canReceive));
    }

    public function store(Request $request, HandleCustomerReturn $returns): RedirectResponse
    {
        abort_unless(app(AdministrationEvidenceService::class)->canReceiveCustomerReturn($request->user()), 403);
        $validated = $request->validate([
            'order_item_id' => ['required', 'integer', Rule::exists(OrderItem::class, 'id')],
            'quantity' => ['required', 'integer', 'min:1', 'max:4294967295'],
            'condition' => ['required', Rule::in(['sealed', 'opened', 'damaged'])],
            'reason_code' => ['required', Rule::in(array_keys(OperationalRequestReason::customerReturnOptions()))],
            'reason' => ['required_if:reason_code,other', 'nullable', 'string', 'min:5', 'max:2000'],
            ...AdministrationEvidenceService::productPhotoRules(),
        ]);
        $returns->receive($request->user(), $validated['order_item_id'], $validated['quantity'], $validated['condition'], OperationalRequestReason::resolve($validated['reason_code'], $validated['reason'] ?? null), $validated['reason_code'], photos: $validated['files']);

        return back();
    }

    public function receiveRequest(Request $request, int $returnRequest, HandleCustomerReturnRequest $returns): RedirectResponse
    {
        abort_unless(app(AdministrationEvidenceService::class)->canReceiveCustomerReturn($request->user()), 403);
        $validated = $request->validate(['condition' => ['required', Rule::in(['sealed', 'opened', 'damaged'])], ...AdministrationEvidenceService::productPhotoRules()]);
        $returnId = $returns->receive($request->user(), $returnRequest, $validated['condition'], $validated['files']);

        return to_route('administration.customer-returns.index', ['case' => $returnId]);
    }

    public function decide(Request $request, int $customerReturn, HandleCustomerReturn $returns): RedirectResponse
    {
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['accept', 'reject'])],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'disposition' => ['required_if:decision,accept', 'prohibited_if:decision,reject', 'nullable', Rule::in(['restock', 'write_off', 'supplier_return', 'return_to_customer'])],
            'approved_amount' => ['required_if:decision,accept', 'prohibited_if:decision,reject', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'supplier_id' => ['required_if:disposition,supplier_return', 'prohibited_if:decision,reject', 'nullable', 'integer', Rule::exists('suppliers', 'id')],
            'inspection_confirmed' => ['sometimes', 'required_if:disposition,restock', 'accepted'],
            'resolution_type' => ['required_if:decision,accept', 'prohibited_if:decision,reject', 'nullable', Rule::in(['refund', 'replacement', 'repair', 'other'])],
            'resolution_reference' => ['prohibited_if:decision,reject', 'nullable', 'string', 'max:255'],
            'inspection_notes' => ['required_if:disposition,restock', 'prohibited_if:decision,reject', 'nullable', 'string', 'min:5', 'max:2000'],
            'public_feedback' => ['nullable', 'string', 'min:5', 'max:2000'],
        ]);
        $accept = $validated['decision'] === 'accept';
        $resolution = ['public_feedback' => isset($validated['public_feedback']) ? (string) $validated['public_feedback'] : null];
        if ($accept) {
            $resolution['type'] = (string) $validated['resolution_type'];
            $resolution['reference'] = isset($validated['resolution_reference']) ? (string) $validated['resolution_reference'] : null;
            $resolution['inspection_notes'] = isset($validated['inspection_notes']) ? (string) $validated['inspection_notes'] : null;
        }
        $returns->decide($customerReturn, $request->user(), $accept, $validated['disposition'] ?? '', (string) ($validated['approved_amount'] ?? '0.00'), $validated['reason'], $validated['supplier_id'] ?? null, isset($validated['inspection_confirmed']), $resolution);

        return back();
    }

    public function handBack(Request $request, int $customerReturn, HandleCustomerReturn $returns): RedirectResponse
    {
        $returns->handBack($customerReturn, $request->user());

        return back();
    }

    public function completeResolution(Request $request, int $customerReturn, HandleCustomerReturn $returns): RedirectResponse
    {
        $validated = $request->validate(['completion_notes' => ['required', 'string', 'min:5', 'max:2000'], 'handover_confirmed' => ['sometimes', 'accepted']]);
        $returns->completeResolution($customerReturn, $request->user(), $validated['completion_notes'], $request->boolean('handover_confirmed'));

        return back();
    }

    public function recordPayment(Request $request, int $refund, HandleCustomerReturn $returns): RedirectResponse
    {
        abort_unless($request->user()->can('returns.approve') && DB::table('customer_refunds')->where('id', $refund)->where('approved_by', $request->user()->id)->exists(), 403);
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999.99'],
            'payment_method' => ['required', Rule::in(['cash', 'gcash', 'maya', 'bank_transfer', 'other'])],
            'submission_key' => ['required', 'uuid'],
            'external_reference' => ['nullable', 'string', 'max:255'],
            'payment_confirmed' => ['required', 'accepted'],
            'proof' => ['required_unless:payment_method,cash', 'prohibited_if:payment_method,cash', 'nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'extensions:jpg,jpeg,png,webp', 'max:5120'],
        ]);
        $returns->recordPayment($refund, $request->user(), (string) $validated['amount'], $validated['payment_method'], $validated['submission_key'], $request->boolean('payment_confirmed'), $validated['proof'] ?? null, $validated['external_reference'] ?? null);

        return back();
    }

    public function reviewPayment(Request $request, int $payment, HandleCustomerReturn $returns): RedirectResponse
    {
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['verified', 'disputed', 'voided'])],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
        ]);
        $returns->reviewPayment($payment, $request->user(), $validated['decision'], $validated['reason']);

        return back();
    }
}
