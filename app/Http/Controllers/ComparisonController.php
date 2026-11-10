<?php

namespace App\Http\Controllers;

use App\FaceEngines\FaceEngine;
use App\FaceEngines\FaceEngineException;
use App\FaceEngines\LocalFaceEngine;
use App\Http\Requests\CompareFacesRequest;
use App\Models\Comparison;
use App\Services\ComparisonForwarder;
use App\Services\FaceMatchService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ComparisonController extends Controller
{
    public function index(FaceEngine $engine, ComparisonForwarder $forwarder): View
    {
        return view('comparisons.index', [
            'recent' => Comparison::latest()->limit(6)->get(),
            'stats' => [
                'total' => Comparison::count(),
                'matches' => Comparison::where('passed', true)->count(),
                'sent' => Comparison::where('forward_status', 'sent')->count(),
            ],
            'service' => $engine instanceof LocalFaceEngine ? $engine->serviceStatus() : null,
            'forwardConfigured' => $forwarder->isConfigured(),
            'defaultThreshold' => (float) config('facematch.default_threshold', 80),
            'onlyMatches' => (bool) config('facematch.forward.only_matches', true),
        ]);
    }

    public function store(CompareFacesRequest $request, FaceMatchService $service): RedirectResponse
    {
        try {
            $comparison = $service->compare(
                $request->file('source_image'),
                $request->file('target_image'),
                $request->mode(),
                $request->threshold(),
                'web',
                // Switch activado: aplica FACE_FORWARD_ONLY_MATCHES; desactivado: no se envía.
                $request->boolean('forward') ? null : false,
            );
        } catch (FaceEngineException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('comparisons.show', $comparison)
            ->with($comparison->successful() ? 'success' : 'warning', $this->flashMessage($comparison));
    }

    public function show(Comparison $comparison, ComparisonForwarder $forwarder): View
    {
        return view('comparisons.show', [
            'comparison' => $comparison,
            'forwardConfigured' => $forwarder->isConfigured(),
            'forwardUrl' => config('facematch.forward.url'),
        ]);
    }

    public function history(): View
    {
        return view('comparisons.history', [
            'comparisons' => Comparison::latest()->paginate(12),
        ]);
    }

    public function forward(Comparison $comparison, ComparisonForwarder $forwarder): RedirectResponse
    {
        if (! $forwarder->isConfigured()) {
            return back()->with('error', 'Configura FACE_FORWARD_URL para enviar resultados al otro proyecto.');
        }

        $forwarder->send($comparison);

        return back()->with(
            $comparison->forward_status === 'sent' ? 'success' : 'error',
            $comparison->forward_status === 'sent'
                ? "Resultado enviado (HTTP {$comparison->forward_http_code})."
                : 'No se pudo enviar: '.($comparison->forward_http_code ? "HTTP {$comparison->forward_http_code}" : $comparison->forward_response),
        );
    }

    public function destroy(Comparison $comparison, FaceMatchService $service): RedirectResponse
    {
        $service->delete($comparison);

        return redirect()->route('comparisons.history')->with('success', 'Comparación eliminada.');
    }

    private function flashMessage(Comparison $comparison): string
    {
        $similarity = number_format($comparison->similarity, 1);

        if ($comparison->isVerify()) {
            return $comparison->passed
                ? "Identidad verificada ({$similarity} %)."
                : 'Identidad rechazada.';
        }

        return $comparison->is_match
            ? "Los rostros coinciden ({$similarity} %)."
            : "Los rostros no coinciden ({$similarity} %).";
    }
}
