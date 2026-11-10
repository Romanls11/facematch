<?php

namespace App\Http\Controllers;

use App\Support\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ActivityLogController extends Controller
{
    public function index(Request $request, ActivityLog $log): View
    {
        $dates = $log->dates();
        $today = now()->toDateString();

        $date = (string) $request->query('fecha', $dates[0] ?? $today);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = $today;
        }

        $filters = [
            'level' => in_array($request->query('nivel'), ActivityLog::LEVELS, true) ? $request->query('nivel') : null,
            'group' => array_key_exists((string) $request->query('tipo'), ActivityLog::GROUPS) ? $request->query('tipo') : null,
            'q' => mb_substr(trim((string) $request->query('q', '')), 0, 100),
        ];

        return view('logs.index', [
            'enabled' => $log->enabled(),
            'dates' => $dates,
            'date' => $date,
            'filters' => $filters,
            'groups' => ActivityLog::GROUPS,
            'result' => $log->read($date, $filters),
            'summary' => $log->summary($date),
        ]);
    }
}
