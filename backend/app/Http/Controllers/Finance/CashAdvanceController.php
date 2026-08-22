<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\CashAdvance;
use App\Models\CashAdvanceExpense;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Finance\CashAdvanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CashAdvanceController extends Controller
{
    public function __construct(private readonly CashAdvanceService $advances) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in([
                CashAdvance::STATUS_OPEN,
                CashAdvance::STATUS_SETTLED,
                CashAdvance::STATUS_CANCELLED,
            ])],
            'academic_year' => ['nullable', 'string', 'max:20'],
            'holder_id' => ['nullable', 'integer', 'exists:users,id'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $rows = CashAdvance::query()
            ->with(['holder:id,name', 'issuedBy:id,name', 'settledBy:id,name'])
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            // Defaults to the year the school is working in; an explicit year
            // is still honoured for a report that reaches back.
            ->when(
                $filters['academic_year'] ?? null,
                fn ($query, $year) => $query->where('academic_year', $year),
                fn ($query) => $query->inActiveYear(),
            )
            ->when($filters['holder_id'] ?? null, fn ($query, $id) => $query->where('holder_id', $id))
            ->when($filters['search'] ?? null, fn ($query, $term) => $query->where(
                fn ($inner) => $inner->where('holder_name', 'like', "%{$term}%")
                    ->orWhere('purpose', 'like', "%{$term}%"),
            ))
            ->orderByDesc('issued_on')
            ->orderByDesc('id')
            ->get();

        $open = $rows->where('status', CashAdvance::STATUS_OPEN);

        return response()->json([
            'data' => $rows->map(fn (CashAdvance $advance) => $this->advances->present($advance))->values(),
            // What the school is still owed account for, at a glance.
            'totals' => [
                'open_count' => $open->count(),
                'open_amount' => round((float) $open->sum('amount'), 2),
                'open_outstanding' => round($open->sum(fn (CashAdvance $advance) => $advance->outstanding()), 2),
            ],
            'methods' => PaymentMethod::orderBy('name')->pluck('name'),
            'staff' => User::whereIn('user_type', ['admin', 'staff', 'teacher'])
                ->orderBy('name')
                ->get(['id', 'name', 'user_type']),
        ]);
    }

    public function show(CashAdvance $cashAdvance): JsonResponse
    {
        return response()->json(['data' => $this->advances->present($cashAdvance, withExpenses: true)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'academic_year' => ['required', 'string', 'max:20'],
            'holder_id' => ['nullable', 'integer', 'exists:users,id'],
            'holder_name' => ['required', 'string', 'max:255'],
            'purpose' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', 'string', 'max:100'],
            'reference' => ['nullable', 'string', 'max:100'],
            'issued_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $advance = $this->advances->issue($data, $request->user());

        return response()->json(['data' => $this->advances->present($advance, withExpenses: true)], 201);
    }

    /**
     * Only the descriptive fields, and only while the advance is open — the
     * amount is what left the till and is not rewritten after the fact.
     */
    public function update(Request $request, CashAdvance $cashAdvance): JsonResponse
    {
        abort_unless($cashAdvance->isOpen(), 422, 'هذه العهدة مقفلة ولا يمكن تعديلها.');

        $data = $request->validate([
            'holder_name' => ['sometimes', 'required', 'string', 'max:255'],
            'holder_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'purpose' => ['sometimes', 'required', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $cashAdvance->update($data);

        return response()->json(['data' => $this->advances->present($cashAdvance->fresh(), withExpenses: true)]);
    }

    public function addExpense(Request $request, CashAdvance $cashAdvance): JsonResponse
    {
        $data = $request->validate([
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'spent_on' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);

        $this->advances->addExpense($cashAdvance, $data, $request->user());

        return response()->json(['data' => $this->advances->present($cashAdvance->fresh(), withExpenses: true)], 201);
    }

    public function removeExpense(Request $request, CashAdvanceExpense $expense): JsonResponse
    {
        $advance = $expense->advance;
        $this->advances->removeExpense($expense, $request->user());

        return response()->json(['data' => $this->advances->present($advance->fresh(), withExpenses: true)]);
    }

    public function settle(Request $request, CashAdvance $cashAdvance): JsonResponse
    {
        $result = $this->advances->settle($cashAdvance, $request->user());

        return response()->json([
            'data' => $this->advances->present($cashAdvance->fresh(), withExpenses: true),
            'message' => $result['returned'] > 0
                ? "أُقفلت العهدة، والمبلغ المرتجع {$result['returned']}."
                : ($result['reimbursed'] > 0
                    ? "أُقفلت العهدة، وللمستلم مستحق قدره {$result['reimbursed']}."
                    : 'أُقفلت العهدة بالكامل دون فروقات.'),
        ]);
    }

    public function reopen(Request $request, CashAdvance $cashAdvance): JsonResponse
    {
        $this->advances->reopen($cashAdvance, $request->user());

        return response()->json([
            'data' => $this->advances->present($cashAdvance->fresh(), withExpenses: true),
            'message' => 'أُعيد فتح العهدة.',
        ]);
    }

    public function cancel(Request $request, CashAdvance $cashAdvance): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        $this->advances->cancel($cashAdvance, $request->user(), $data['reason'] ?? null);

        return response()->json([
            'data' => $this->advances->present($cashAdvance->fresh(), withExpenses: true),
            'message' => 'أُلغيت العهدة.',
        ]);
    }
}
