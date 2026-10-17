<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Audit\RecordActivity;
use App\Enums\ActivityEvent;
use App\Models\Activity;
use App\Support\Audit\ActivityFilters;
use App\Support\Audit\ActivityRows;
use App\Tenancy\HostScope;
use Illuminate\Http\Request;
use Illuminate\Support\LazyCollection;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /activity/export (§4.8, §6.6, CD-5): the activity log as CSV, with the viewer's filters and the host's scope.
 * The route checks activity_log.view and the `activity-export` limiter; on a central host VerifyTenantMembership
 * lets only super admins through. The rows stream in id order, 500 per query (lazyById), so a large log is never
 * held in memory: at most one chunk of entries and its name lookups at a time. Not a Livewire download, which
 * would buffer the whole file into the update's JSON response.
 */
final class ActivityExportController extends Controller
{
    /** Cells that start like a formula are prefixed with an apostrophe, so a spreadsheet shows them as text. */
    private const array FORMULA_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    public function __invoke(Request $request, RecordActivity $recordActivity): StreamedResponse
    {
        $filters = ActivityFilters::fromQuery($request->query());
        $central = ActivityFilters::isCentral();

        // Recorded before the first byte, with the filters that take effect; ignored values are left out.
        $recordActivity->handle(ActivityEvent::ActivityExported, properties: ['filters' => $filters->applied()]);

        return response()->streamDownload(function () use ($filters, $central): void {
            $output = fopen('php://output', 'wb') ?: throw new RuntimeException('The export cannot open the output stream.');

            // A UTF-8 byte order mark, so Excel reads the names and labels as UTF-8.
            fwrite($output, "\u{FEFF}");
            $this->write($output, $this->header($central));

            HostScope::read(function () use ($filters, $central, $output): void {
                $filters->query()->lazyById(500)->chunk(500)->each(function (LazyCollection $chunk) use ($central, $output): void {
                    /** @var list<Activity> $activities */
                    $activities = $chunk->values()->all();

                    foreach (ActivityRows::of($activities) as $index => $row) {
                        $this->write($output, $this->line($activities[$index], $row, $central));
                    }
                });
            });

            fclose($output);
        }, 'activity-log-'.now()->format('Y-m-d-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /** @return list<string> */
    private function header(bool $central): array
    {
        return [
            __('Date'),
            __('Event key'),
            __('Action'),
            __('Done by'),
            __('Subject'),
            ...($central ? [__('Organisation')] : []),
            __('IP address'),
            __('User agent'),
            __('Properties'),
        ];
    }

    /**
     * One entry as CSV cells; IP address and user agent are empty where the viewer hides them (RL-7).
     *
     * @param  array{event: string, event_key: string, causer: string, subject: string, organisation: string|null, ip_address: string|null, user_agent: string|null}  $row
     * @return list<string>
     */
    private function line(Activity $activity, array $row, bool $central): array
    {
        return [
            $activity->created_at?->toIso8601String() ?? '',
            $row['event_key'],
            $row['event'],
            $row['causer'],
            $row['subject'],
            ...($central ? [(string) $row['organisation']] : []),
            (string) $row['ip_address'],
            (string) $row['user_agent'],
            json_encode($activity->properties?->toArray() ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * @param  resource  $output
     * @param  list<string>  $cells
     */
    private function write($output, array $cells): void
    {
        fputcsv($output, array_map($this->neutralise(...), $cells), ',', '"', '');
    }

    /** CSV formula injection: a cell a spreadsheet would run as a formula starts with an apostrophe instead. */
    private function neutralise(string $cell): string
    {
        return $cell !== '' && in_array($cell[0], self::FORMULA_PREFIXES, true) ? "'".$cell : $cell;
    }
}
