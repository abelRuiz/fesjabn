<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use App\Models\Inscrito;
use Illuminate\Http\Request;

class InscritoController extends Controller
{
    public function index()
    {
        $query = request('query');
        $iglesia = request('iglesia');

        $inscritos = Inscrito::when($query, function ($q) use ($query) {
                $q->where(function ($qq) use ($query) {
                    $qq->where('nombre', 'like', "%{$query}%")
                    ->orWhere('id', $query)
                    ->orWhere('iglesia', 'like', "%{$query}%")
                    ->orWhere('distrito', 'like', "%{$query}%");
                });
            })
            ->when($iglesia, fn ($q) => $q->where('iglesia', $iglesia))
            ->orderBy('distrito')
            ->orderBy('iglesia')
            ->paginate(50)
            ->withQueryString();

        // lista para el select (únicos)
        $iglesias = Inscrito::select('iglesia')
            ->whereNotNull('iglesia')
            ->where('iglesia', '!=', '')
            ->groupBy('iglesia')
            ->orderBy('iglesia')
            ->pluck('iglesia');

        return Inertia::render('Inscritos', [
            'inscritos' => $inscritos,
            'query'     => $query,
            'iglesia'   => $iglesia,
            'iglesias'  => $iglesias, // array simple
        ]);
    }


    public function update(Request $request)
    {
        $request->validate([
            'action' => ['required', 'string', 'in:entrada,salida'],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'exists:inscritos,id']
        ]);

        Inscrito::whereIn('id', $request->ids)
            ->update([$request->action => now()]);

        return redirect()->route('inscritos.index')->with('success', 'Actualizado');
    }
}
