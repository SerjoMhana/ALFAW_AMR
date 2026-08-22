<?php

namespace App\Http\Controllers;

use App\Models\CalendarEvent;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The school calendar.
 *
 * Reading is open to everyone signed in — that is the point of publishing it to
 * parents, students and teachers. Writing needs `calendar.manage`.
 */
class CalendarEventController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'kind' => ['nullable', Rule::in(CalendarEvent::KINDS)],
        ]);

        // A month by default, because that is what the screen shows.
        if (isset($validated['from'], $validated['to'])) {
            $from = CarbonImmutable::parse($validated['from']);
            $to = CarbonImmutable::parse($validated['to']);
        } else {
            $anchor = CarbonImmutable::create(
                $validated['year'] ?? now()->year,
                $validated['month'] ?? now()->month,
                1,
            );
            $from = $anchor->startOfMonth();
            $to = $anchor->endOfMonth();
        }

        $events = CalendarEvent::query()
            // An event tagged with a past year is that year's business; one with
            // no year at all belongs to the school generally.
            ->where(fn ($query) => $query->whereNull('academic_year')->orWhere(
                fn ($inner) => $inner->inActiveYear(),
            ))
            ->overlapping($from->toDateString(), $to->toDateString())
            ->when($validated['kind'] ?? null, fn ($query, $kind) => $query->where('kind', $kind))
            ->orderBy('starts_on')
            ->orderBy('starts_at')
            ->get();

        return response()->json([
            'data' => $events->map(fn (CalendarEvent $event) => $this->present($event)),
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'kinds' => CalendarEvent::KINDS,
            'kind_colors' => CalendarEvent::KIND_COLORS,
            'can_manage' => $this->canManage($request),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($this->canManage($request), 403);

        $data = $this->validated($request);
        $data['created_by'] = $request->user()->id;

        return response()->json(['data' => $this->present(CalendarEvent::create($data))], 201);
    }

    public function update(Request $request, CalendarEvent $calendarEvent): JsonResponse
    {
        abort_unless($this->canManage($request), 403);

        $calendarEvent->update($this->validated($request));

        return response()->json(['data' => $this->present($calendarEvent->fresh())]);
    }

    public function destroy(Request $request, CalendarEvent $calendarEvent): JsonResponse
    {
        abort_unless($this->canManage($request), 403);

        $calendarEvent->delete();

        return response()->json(['message' => 'تم حذف الفعالية من التقويم.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'kind' => ['required', Rule::in(CalendarEvent::KINDS)],
            // Hex only: the value is written straight into a style attribute.
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'all_day' => ['boolean'],
            'starts_at' => ['nullable', 'date_format:H:i'],
            'ends_at' => ['nullable', 'date_format:H:i', 'after:starts_at'],
            'location' => ['nullable', 'string', 'max:255'],
            'academic_year' => ['nullable', 'string', 'max:20'],
        ]);

        // Optional fields are absent from the validated set when they were not
        // sent at all, so each one is filled rather than assumed present.
        $data['ends_on'] = $data['ends_on'] ?? $data['starts_on'];
        $data['all_day'] = $data['all_day'] ?? true;
        $data['color'] = ($data['color'] ?? null) ?: (CalendarEvent::KIND_COLORS[$data['kind']] ?? '#465fff');

        if ($data['all_day']) {
            $data['starts_at'] = null;
            $data['ends_at'] = null;
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(CalendarEvent $event): array
    {
        return [
            'id' => $event->id,
            'title' => $event->title,
            'description' => $event->description,
            'kind' => $event->kind,
            'color' => $event->color,
            'starts_on' => $event->starts_on->toDateString(),
            'ends_on' => $event->ends_on->toDateString(),
            'all_day' => $event->all_day,
            'starts_at' => $event->starts_at ? substr((string) $event->starts_at, 0, 5) : null,
            'ends_at' => $event->ends_at ? substr((string) $event->ends_at, 0, 5) : null,
            'location' => $event->location,
            'academic_year' => $event->academic_year,
            'days' => $event->starts_on->diffInDays($event->ends_on) + 1,
        ];
    }

    private function canManage(Request $request): bool
    {
        return $request->user()->isAdmin() || $request->user()->hasPermission('calendar.manage');
    }
}
