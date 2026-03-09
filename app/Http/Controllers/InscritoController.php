<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use App\Models\Checkin;
use App\Models\Inscrito;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class InscritoController extends Controller
{
    public function index()
    {
        $raw = trim((string) request('query', ''));   // lo que venga en ?query=
        $iglesia = request('iglesia');
        $distrito = request('distrito');
        $status = request()->input('status',false);
                 // (si ya usas este filtro)
        $ids = collect(preg_split('/[,\s;|]+/', $raw, -1, PREG_SPLIT_NO_EMPTY))
            ->map(function ($v) {
                return filter_var($v, FILTER_VALIDATE_INT) !== false ? (int) $v : null;
        })
        ->filter()       // quita nulls
        ->unique()
        ->values();

        $inscritos = Inscrito::query()
        ->with([
            'lastCheckinEntrada',
            'lastCheckinSalida'
        ])
        ->when($status, function($query) use ($status) {
           $query->whereHas('checkins', function($q) use ($status) {
               $q->where('tipo', $status);
           });
        })
        ->when($distrito, fn ($q) => $q->where('distrito', $distrito))
        ->when($iglesia, fn ($q) => $q->where('iglesia', $iglesia))
        ->when($ids->isNotEmpty(), function ($q) use ($ids) {
            $q->whereIn('id', $ids);
        }, function ($q) use ($raw) {
            if ($raw !== '') {
                $q->where(function ($qq) use ($raw) {
                    $qq->where('nombre', 'like', "%{$raw}%")
                       ->orWhere('iglesia', 'like', "%{$raw}%")
                       ->orWhere('distrito', 'like', "%{$raw}%");

                    // si el raw es numérico simple, también probar id exacto
                    if (filter_var($raw, FILTER_VALIDATE_INT) !== false) {
                        $qq->orWhere('id', (int) $raw);
                    }
                });
            }
        })
        ->orderBy('distrito')
        ->orderBy('iglesia')
        ->paginate(100)
        ->withQueryString();

        // lista para el select (únicos)
        $iglesias = Inscrito::select('iglesia')
            ->whereNotNull('iglesia')
            ->where('iglesia', '!=', '')
            ->groupBy('iglesia')
            ->orderBy('iglesia')
            ->pluck('iglesia');
        $distritos = Inscrito::select('distrito')
            ->whereNotNull('distrito')
            ->where('distrito', '!=', '')
            ->groupBy('distrito')
            ->orderBy('distrito')
            ->pluck('distrito');
        
        return Inertia::render('Inscritos', [
            'inscritos' => $inscritos,
            'query'     => $raw,
            'iglesia'   => $iglesia,
            'iglesias'  => $iglesias, // array simple
            'distritos' => $distritos,
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
                // Bloquear si YA está adentro: buscar su ultimo checkin y que sea entrada

                $yaAdentro = Inscrito::whereIn('id', $ids)
                    ->whereHas('checkinActual', function ($q) {
                        $q->where('tipo', 'entrada');
                    })
                    ->pluck('id');
                   // dd($yaAdentro);
                if ($yaAdentro->isNotEmpty()) {
                    $v->errors()->add('ids', 'Algunos inscritos ya están ADENTRO. IDs: '.$yaAdentro->implode(', '));
                }
            } else { // salida
                // Bloquear si su ultimo checkin es salida
                $noAdentro = Inscrito::whereIn('id', $ids)
                    ->whereHas('checkinActual', function ($q) {
                        $q->where('tipo', 'salida');
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
                // insertar entrada en tabla de checkins
                foreach ($ids as $id) {
                    Checkin::create([
                        'inscrito_id' => $id,
                        'tipo' => 'entrada',
                        'fecha' => now(),
                    ]);
                }
                    
            } else { // salida
                // Cierre de ciclo: solo si está adentro (entrada != null && salida == null)
                foreach ($ids as $id) {
                    Checkin::create([
                        'inscrito_id' => $id,
                        'tipo' => 'salida',
                        'fecha' => now(),
                    ]);
                }
            }
        });

        return redirect()->route('inscritos.index')->with('success', 'Actualizado');
    }

}
