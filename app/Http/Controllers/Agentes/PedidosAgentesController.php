<?php

namespace App\Http\Controllers\Agentes;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Mail;

class PedidosAgentesController extends Controller
{
    protected function fb()
    {
        return DB::connection('firebird');
    }

    protected function getEmpresa(): string
    {
        $fbDatabase = env('FB_DATABASE', '');
        preg_match('/\d{2}/', $fbDatabase, $matches);
        $empresa = $matches[0] ?? '01';
        Log::info('empresa de pedidos', ['empresa' => $empresa]);
        return $empresa;
    }

protected function getAgenteClave()
{
    Log::info('🔍 Entrando a getAgenteClave');

    $user = Auth::user();

    if (!$user) {
        Log::warning('⛔ Usuario no autenticado');
        abort(401, 'No autenticado');
    }

    Log::info('👤 Usuario autenticado', [
        'user_id' => $user->id ?? null,
        'firebird_ID' => $user->ID ?? null,
        'email' => $user->email ?? null,
    ]);

    $identityQuery = DB::connection('mysql')
        ->table('users_firebird_identities')
        ->where('firebird_user_clave', $user->ID)
        ->where('firebird_vend_tabla', 'VEND03')
        ->whereNotNull('firebird_vend_clave');

    Log::info('🧠 Query preparada', [
        'firebird_user_clave' => $user->ID,
        'firebird_vend_tabla' => 'VEND03',
    ]);

    $identity = $identityQuery->first();

    if (!$identity) {
        Log::warning('⛔ No se encontró identidad vinculada', [
            'firebird_user_clave' => $user->ID,
        ]);
        abort(403, 'No es cliente CLIE');
    }

    Log::info('✅ Identidad encontrada', [
        'firebird_vend_clave' => $identity->firebird_vend_clave,
        'registro_completo' => $identity,
    ]);

    return $identity->firebird_vend_clave;
}


    protected function sanitize($value): string
    {
        return trim((string) ($value ?? ''));
    }

    /* =======================================================
        🛒 SP - todos los pedidos de la empresa
    ======================================================= */
    protected function getPedidosSP(): \Illuminate\Support\Collection
    {
        $empresa = $this->getEmpresa();
        return collect($this->fb()->select("SELECT * FROM P_PEDIDOSENCMAIN(?)", [$empresa]));
    }

    /* =======================================================
        📦 Artículos y cardigans usando ID (int) del SP
        V_PED_PART.CVE_PED = SP.ID  (no SP.PEDIDO)
    ======================================================= */
    protected function getPartidasPorIds(array $ids): array
    {
        if (empty($ids)) return [
            'articulos' => collect(),
            'cardigans' => collect(),
        ];

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $articulos = $this->fb()->select(
            "SELECT CVE_PED, ARTICULO, SUM(CANTIDAD) AS CANTIDAD
             FROM V_PED_PART
             WHERE CVE_PED IN ({$placeholders})
             GROUP BY CVE_PED, ARTICULO",
            $ids
        );

        $cardigans = $this->fb()->select(
            'SELECT CVE_PED, "CARDIGAN DESCR." AS DESCRIPCION, SUM("CANT. CARD.") AS CANTIDAD
             FROM V_PED_PART
             WHERE CVE_PED IN (' . $placeholders . ')
               AND "CANT. CARD." > 0
             GROUP BY CVE_PED, "CARDIGAN DESCR."',
            $ids
        );

        return [
            // Key por ID como string para lookup fácil
            'articulos' => collect($articulos)->groupBy(fn($r) => (string) $r->CVE_PED),
            'cardigans' => collect($cardigans)->groupBy(fn($r) => (string) $r->CVE_PED),
        ];
    }

    /* =======================================================
        🔧 mapPedido
        cve_ped  = PEDIDO  ("260181") — se muestra al usuario
        id       = ID      (3578)     — se usa para V_PED_PART
    ======================================================= */
    protected function mapPedido(object $item, array $articulos = [], array $cardigans = []): array
    {
        return [
            'id'            => (int)   ($item->ID      ?? 0),
            'anio'          => (int)   ($item->ANIO     ?? 0),
            'cve_ped'       => $this->sanitize($item->PEDIDO    ?? ''),   // "260181"
            'pedido_n'      => $this->sanitize($item->PEDIDON   ?? ''),   // "181"
            'cve_clie'      => $this->sanitize($item->CVE_CTE   ?? ''),
            'nombre'        => $this->sanitize($item->CLIENTE    ?? ''),
            'referencia'    => $this->sanitize($item->REFERENCIA ?? ''),
            'tipo_venta'    => $this->sanitize($item->{'TIPO VENTA'}    ?? ''),
            'estatus'       => $this->sanitize($item->ESTATUS            ?? ''),
            'autorizado'    => $this->sanitize($item->AUTORIZADO         ?? ''),
            'condicion'     => $this->sanitize($item->CONDICIONES        ?? ''),
            'credito'       => $this->sanitize($item->CREDITO            ?? 'NO'),
            'dias_credito'  => (int) ($item->{'DIAS DE CREDITO'}         ?? 0),
            'agente'        => $this->sanitize($item->{'NOMBRE AGENTE'}  ?? ''),
            'fecha_elab'    => $this->parseDate($item->{'FECHA ELAB.'}   ?? null),
            'fecha_entrega' => $this->parseDate($item->{'FECHA ENT.'}    ?? null),
            'fecha_pago'    => $this->parseDate($item->{'FECHA PAGO'}    ?? null),
            'usuario'       => $this->sanitize($item->USUARIO            ?? ''),
            'observaciones' => $this->sanitize($item->OBSERVACIONES      ?? ''),
            'status'        => $this->sanitize($item->{'PARC. O COMPL.'} ?? ''),
            'articulos'     => array_map(fn($a) => (array) $a, $articulos),
            'cardigans'     => array_map(fn($c) => (array) $c, $cardigans),
        ];
    }

    protected function parseDate($value): ?string
    {
        if (empty($value)) return null;
        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Exception $e) {
            return null;
        }
    }

    /* =======================================================
        📄 INDEX
    ======================================================= */
public function index(Request $request)
{
    try {
        $cveVend = (int) $this->getAgenteClave();

        $pedidosSP = $this->getPedidosSP()
            ->filter(fn($item) => (int) ($item->AGENTE ?? 0) === $cveVend)
            ->sortByDesc(fn($item) => $item->{'FECHA ELAB.'} ?? '')
            ->values();

        $ids    = $pedidosSP->pluck('ID')->filter()->map(fn($id) => (int) $id)->values()->toArray();
        $extras = $this->getPartidasPorIds($ids);

        $pedidos = $pedidosSP->map(function ($item) use ($extras) {
            $id        = (string) ($item->ID ?? '');
            $articulos = $extras['articulos']->get($id, collect())->values()->toArray();
            $cardigans = $extras['cardigans']->get($id, collect())->values()->toArray();
            return $this->mapPedido($item, $articulos, $cardigans);
        })->values();

        return response()->json([
            'success' => true,
            'data'    => $pedidos,
            'total'   => $pedidos->count(),
        ]);
    } catch (\Exception $e) {
        Log::error('ERROR_INDEX_PEDIDOS', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        return response()->json(['success' => false, 'message' => 'Error al obtener pedidos', 'error' => $e->getMessage()], 500);
    }
}


    /* =======================================================
        🔍 SHOW
    ======================================================= */
public function show(string $cvePed)
{
    try {
        $cveVend   = (int) $this->getAgenteClave();
        $resultado = $this->getPedidosSP()->first(
            fn($item) =>
            (int) ($item->AGENTE ?? 0) === $cveVend &&
            $this->sanitize($item->PEDIDO ?? '') === $this->sanitize($cvePed)
        );

        if (!$resultado) return response()->json(['success' => false, 'message' => 'Pedido no encontrado'], 404);

        $idStr  = (string) ($resultado->ID ?? '');
        $extras = $this->getPartidasPorIds([(int) ($resultado->ID ?? 0)]);

        return response()->json(['success' => true, 'data' => $this->mapPedido(
            $resultado,
            $extras['articulos']->get($idStr, collect())->values()->toArray(),
            $extras['cardigans']->get($idStr, collect())->values()->toArray()
        )]);
    } catch (\Exception $e) {
        Log::error('ERROR_SHOW_PEDIDO', ['message' => $e->getMessage(), 'cve_ped' => $cvePed]);
        return response()->json(['success' => false, 'message' => 'Error al obtener el pedido', 'error' => $e->getMessage()], 500);
    }
}


    /* =======================================================
        📊 RESUMEN
    ======================================================= */
public function resumen()
{
    try {
        $cveVend = (int) $this->getAgenteClave();
        $datos   = $this->getPedidosSP()
            ->filter(fn($item) => (int) ($item->AGENTE ?? 0) === $cveVend);

        $hoy      = Carbon::now();
        $vencidos = $datos->filter(function ($item) use ($hoy) {
            $fecha = $item->{'FECHA ENT.'} ?? null;
            if (empty($fecha)) return false;
            try { return Carbon::parse($fecha)->lt($hoy); }
            catch (\Exception $e) { return false; }
        });

        return response()->json([
            'success' => true,
            'data'    => [
                'total_pedidos'    => $datos->count(),
                'pedidos_vencidos' => $vencidos->count(),
                'completos'        => $datos->filter(fn($i) => $this->sanitize($i->{'PARC. O COMPL.'} ?? '') === 'Completo')->count(),
                'parciales'        => $datos->filter(fn($i) => $this->sanitize($i->{'PARC. O COMPL.'} ?? '') === 'Parcial')->count(),
                'sin_def'          => $datos->filter(fn($i) => str_contains($this->sanitize($i->{'PARC. O COMPL.'} ?? ''), 'Sin'))->count(),
            ],
        ]);
    } catch (\Exception $e) {
        Log::error('ERROR_RESUMEN_PEDIDOS', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        return response()->json(['success' => false, 'message' => 'Error al obtener resumen', 'error' => $e->getMessage()], 500);
    }
}

    /* =======================================================
        📅 POR AÑO
    ======================================================= */
public function porAnio(int $anio)
{
    try {
        $cveVend   = (int) $this->getAgenteClave();
        $pedidosSP = $this->getPedidosSP()
            ->filter(
                fn($item) =>
                (int) ($item->AGENTE ?? 0) === $cveVend &&
                (int) ($item->ANIO   ?? 0) === $anio
            )
            ->sortByDesc(fn($item) => $item->{'FECHA ELAB.'} ?? '')
            ->values();

        $ids    = $pedidosSP->pluck('ID')->filter()->map(fn($id) => (int) $id)->values()->toArray();
        $extras = $this->getPartidasPorIds($ids);

        $pedidos = $pedidosSP->map(function ($item) use ($extras) {
            $idStr = (string) ($item->ID ?? '');
            return $this->mapPedido(
                $item,
                $extras['articulos']->get($idStr, collect())->values()->toArray(),
                $extras['cardigans']->get($idStr, collect())->values()->toArray()
            );
        })->values();

        return response()->json(['success' => true, 'anio' => $anio, 'data' => $pedidos, 'total' => $pedidos->count()]);
    } catch (\Exception $e) {
        Log::error('ERROR_POR_ANIO_PEDIDOS', ['message' => $e->getMessage(), 'anio' => $anio]);
        return response()->json(['success' => false, 'message' => 'Error al obtener pedidos por año', 'error' => $e->getMessage()], 500);
    }
}

    /* =======================================================
        📄 PDF
    ======================================================= */
public function descargarPDF(string $cvePed)
{
    try {
        $cveVend   = (int) $this->getAgenteClave();
        $resultado = $this->getPedidosSP()->first(
            fn($item) =>
            (int) ($item->AGENTE ?? 0) === $cveVend &&
            $this->sanitize($item->PEDIDO ?? '') === $this->sanitize($cvePed)
        );

        if (!$resultado) return response()->json(['success' => false, 'message' => 'Pedido no encontrado'], 404);

        $idStr  = (string) ($resultado->ID ?? '');
        $extras = $this->getPartidasPorIds([(int) ($resultado->ID ?? 0)]);

        $pdf = Pdf::loadView('pdfs.pedido', [
            'pedido'           => $this->mapPedido(
                $resultado,
                $extras['articulos']->get($idStr, collect())->values()->toArray(),
                $extras['cardigans']->get($idStr, collect())->values()->toArray()
            ),
            'fecha_generacion' => Carbon::now()->format('d/m/Y H:i:s'),
        ]);

        return $pdf->download("pedido-{$cvePed}.pdf");
    } catch (\Exception $e) {
        Log::error('ERROR_DESCARGAR_PDF_PEDIDO', ['message' => $e->getMessage(), 'cve_ped' => $cvePed]);
        return response()->json(['success' => false, 'message' => 'Error al generar PDF', 'error' => $e->getMessage()], 500);
    }
}

    /* =======================================================
        📦 DESCARGAR MÚLTIPLES
    ======================================================= */
public function descargarMultiples(Request $request)
{
    try {
        $request->validate(['pedidos' => 'required|array|min:1', 'pedidos.*' => 'required|string']);

        $cveVend    = (int) $this->getAgenteClave();
        $cvePedidos = array_map([$this, 'sanitize'], $request->pedidos);

        $pedidosSP = $this->getPedidosSP()->filter(
            fn($item) =>
            (int) ($item->AGENTE ?? 0) === $cveVend &&
            in_array($this->sanitize($item->PEDIDO ?? ''), $cvePedidos)
        )->values();

        if ($pedidosSP->isEmpty()) return response()->json(['success' => false, 'message' => 'No se encontraron pedidos'], 404);

        $ids    = $pedidosSP->pluck('ID')->filter()->map(fn($id) => (int) $id)->values()->toArray();
        $extras = $this->getPartidasPorIds($ids);

        $pedidos = $pedidosSP->map(function ($item) use ($extras) {
            $idStr = (string) ($item->ID ?? '');
            return $this->mapPedido(
                $item,
                $extras['articulos']->get($idStr, collect())->values()->toArray(),
                $extras['cardigans']->get($idStr, collect())->values()->toArray()
            );
        })->values();

        $pdf = Pdf::loadView('pdfs.pedidos-multiples', [
            'pedidos'          => $pedidos,
            'fecha_generacion' => Carbon::now()->format('d/m/Y H:i:s'),
        ]);

        return $pdf->download("pedidos-" . date('YmdHis') . ".pdf");
    } catch (\Exception $e) {
        Log::error('ERROR_DESCARGAR_MULTIPLES_PEDIDOS', ['message' => $e->getMessage()]);
        return response()->json(['success' => false, 'message' => 'Error al generar PDF múltiple', 'error' => $e->getMessage()], 500);
    }
}

    /* =======================================================
        📧 EMAIL
    ======================================================= */
public function enviarEmail(Request $request, string $cvePed)
{
    try {
        $request->validate(['email' => 'required|email']);

        $cveVend   = (int) $this->getAgenteClave();
        $resultado = $this->getPedidosSP()->first(
            fn($item) =>
            (int) ($item->AGENTE ?? 0) === $cveVend &&
            $this->sanitize($item->PEDIDO ?? '') === $this->sanitize($cvePed)
        );

        if (!$resultado) return response()->json(['success' => false, 'message' => 'Pedido no encontrado'], 404);

        $idStr  = (string) ($resultado->ID ?? '');
        $extras = $this->getPartidasPorIds([(int) ($resultado->ID ?? 0)]);
        $data   = [
            'pedido'           => $this->mapPedido(
                $resultado,
                $extras['articulos']->get($idStr, collect())->values()->toArray(),
                $extras['cardigans']->get($idStr, collect())->values()->toArray()
            ),
            'fecha_generacion' => Carbon::now()->format('d/m/Y H:i:s'),
        ];

        $pdf = Pdf::loadView('pdfs.pedido', $data);
        Mail::send('emails.pedido', $data, function ($message) use ($request, $pdf, $cvePed) {
            $message->to($request->email)->subject('Pedido - ' . $cvePed)->attachData($pdf->output(), "pedido-{$cvePed}.pdf");
        });

        return response()->json(['success' => true, 'message' => 'Email enviado correctamente']);
    } catch (\Exception $e) {
        Log::error('ERROR_ENVIAR_EMAIL_PEDIDO', ['message' => $e->getMessage(), 'cve_ped' => $cvePed]);
        return response()->json(['success' => false, 'message' => 'Error al enviar email', 'error' => $e->getMessage()], 500);
    }
}

    /* =======================================================
        🗑 DELETE (no permitido)
    ======================================================= */
    public function destroy(string $cvePed)
    {
        return response()->json(['success' => false, 'message' => 'No se permite eliminar pedidos'], 403);
    }
}