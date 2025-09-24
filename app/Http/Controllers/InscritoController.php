<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use App\Models\Inscrito;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

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
        // 1) Validación base
        $baseRules = [
            'action' => ['required', 'string', Rule::in(['entrada','salida'])],
            'ids'    => ['required', 'array', 'min:1'],
            'ids.*'  => ['required', 'integer', Rule::exists('inscritos', 'id')],
        ];

        $validator = Validator::make($request->all(), $baseRules);

        // 2) Validación de estado según acción (en bloque)
        $validator->after(function ($v) use ($request) {
            $ids = collect($request->input('ids', []))
                ->map(fn($id) => (int)$id)->filter();

            if ($ids->isEmpty()) return;

            if ($request->action === 'entrada') {
                // Bloquear si YA está adentro: entrada != null && salida == null
                $yaAdentro = Inscrito::whereIn('id', $ids)
                    ->whereNotNull('entrada')
                    ->whereNull('salida')
                    ->pluck('id');

                if ($yaAdentro->isNotEmpty()) {
                    $v->errors()->add('ids', 'Algunos inscritos ya están ADENTRO. IDs: '.$yaAdentro->implode(', '));
                }
            } else { // salida
                // Permitir salida SOLO si está adentro: entrada != null && salida == null
                $noAdentro = Inscrito::whereIn('id', $ids)
                    ->where(function ($q) {
                        $q->whereNull('entrada')   // nunca entró
                        ->orWhereNotNull('salida'); // ya salió
                    })
                    ->pluck('id');

                if ($noAdentro->isNotEmpty()) {
                    $v->errors()->add('ids', 'Algunos inscritos NO están ADENTRO. IDs: '.$noAdentro->implode(', '));
                }
            }
        });

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        $action = $request->action;
        $ids    = $request->ids;

        // 3) Update con filtros (transacción)
        DB::transaction(function () use ($action, $ids) {
            if ($action === 'entrada') {
                // Nuevo ciclo: permitir si estaba "afuera" (salida != null) o "no llegó" (ambas null)
                Inscrito::whereIn('id', $ids)
                    ->where(function ($q) {
                        $q->whereNull('entrada')        // no llegó
                        ->orWhereNotNull('salida');   // afuera
                    })
                    ->update([
                        'entrada' => now(),
                        'salida'  => null,  // al entrar, limpia salida
                    ]);
            } else { // salida
                // Cierre de ciclo: solo si está adentro (entrada != null && salida == null)
                Inscrito::whereIn('id', $ids)
                    ->whereNotNull('entrada')
                    ->whereNull('salida')
                    ->update([
                        'salida'  => now(),
                        'entrada' => null,
                    ]);
            }
        });

        return redirect()->route('inscritos.index')->with('success', 'Actualizado');
    }

}
