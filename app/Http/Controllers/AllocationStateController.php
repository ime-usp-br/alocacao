<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAllocationStateRequest;
use App\Models\AllocationState;
use App\Models\SchoolTerm;
use App\Services\AllocationStateService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Session;

class AllocationStateController extends Controller
{
    /**
     * Display a listing of allocation states for the current school term.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        if (!Auth::check() || !Auth::user()->hasRole(["Administrador", "Operador"])) {
            abort(403);
        }

        $schoolterm = SchoolTerm::getLatest();

        $states = AllocationState::whereBelongsTo($schoolterm)
            ->orderByDesc('created_at')
            ->get();

        $cached = Cache::get("allocation:{$schoolterm->id}");
        $isSolving = ($cached['status'] ?? '') === 'solving';

        return response()->json([
            'states' => $states,
            'is_solving' => $isSolving,
        ]);
    }

    /**
     * Store a new manual allocation state.
     *
     * @param StoreAllocationStateRequest $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(StoreAllocationStateRequest $request)
    {
        if (!Auth::check() || !Auth::user()->hasRole(["Administrador", "Operador"])) {
            abort(403);
        }

        $schoolterm = SchoolTerm::getLatest();

        if (! $schoolterm) {
            Session::put("alert-warning", "Não há semestre letivo ativo para salvar o estado.");
            return back();
        }

        $name = $request->input('name') ?: now()->format('d/m/Y H:i:s');

        $state = AllocationStateService::capture($schoolterm, $name);

        Session::put("alert-info", "Estado '{$state->name}' salvo com sucesso.");
        return back();
    }

    /**
     * Restore a previously saved allocation state.
     *
     * @param AllocationState $state
     * @return \Illuminate\Http\RedirectResponse
     */
    public function restore(AllocationState $state)
    {
        if (!Auth::check() || !Auth::user()->hasRole(["Administrador", "Operador"])) {
            abort(403);
        }

        $schoolterm = SchoolTerm::getLatest();

        if (! $schoolterm) {
            Session::put("alert-warning", "Não há semestre letivo ativo.");
            return back();
        }

        $cached = Cache::get("allocation:{$schoolterm->id}");
        if (($cached['status'] ?? '') === 'solving') {
            Session::put("alert-warning", "Não é possível carregar um estado enquanto o solver estiver em execução.");
            return back();
        }

        $result = AllocationStateService::restore($state);

        $message = "Estado restaurado com sucesso!";
        if ($result['missing'] > 0 || $result['unassigned'] > 0) {
            $message = "Estado restaurado! Aviso: {$result['missing']} turmas do save não existem mais e foram ignoradas; {$result['unassigned']} novas turmas ficaram sem sala.";
        }

        Session::put("alert-info", $message);
        return back();
    }

    /**
     * Remove the specified allocation state.
     *
     * @param AllocationState $state
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy(AllocationState $state)
    {
        if (!Auth::check() || !Auth::user()->hasRole(["Administrador", "Operador"])) {
            abort(403);
        }

        $state->delete();

        Session::put("alert-info", "Estado removido com sucesso.");
        return back();
    }
}
