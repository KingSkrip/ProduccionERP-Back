<?php

namespace App\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use App\Models\Firebird\Users;
use App\Models\UserFirebirdIdentity;
use App\Services\FirebirdEmpresaManualService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\FirebirdConnectionService;

class UserService
{
    private string $jwtSecret;
    protected FirebirdConnectionService $firebirdService;

    public function __construct(FirebirdConnectionService $firebirdService)
    {
        $this->jwtSecret = config('jwt.secret') ?? env('JWT_SECRET');
        $this->firebirdService = $firebirdService;
    }

    public function getIdentityFromToken(Request $request): ?UserFirebirdIdentity
    {
        $token = $request->bearerToken();
        if (!$token) return null;

        $decoded = JWT::decode($token, new Key($this->jwtSecret, 'HS256'));
        $sub = (int) $decoded->sub;
        if (!$sub) return null;

        return UserFirebirdIdentity::where('firebird_user_clave', $sub)->first();
    }

    /**
     * Retorna los datos del usuario logueado incluyendo
     * TB, CLIE, VEND y tipo_usuario — igual que el me() del DataDashboardController
     */
    public function me(Request $request): ?array
    {
        $token = $request->bearerToken();
        if (!$token) return null;

        $decoded = JWT::decode($token, new Key($this->jwtSecret, 'HS256'));
        $sub = (int) $decoded->sub;
        if (!$sub) return null;

        // ── Usuario Firebird ──
        $usuario = Users::find($sub);
        if (!$usuario) {
            $usuario = Users::where('CLAVE', $sub)->first();
        }
        if (!$usuario) return null;

        // ── Identity MySQL ──
        $identity = UserFirebirdIdentity::where('firebird_user_clave', (int)$usuario->ID)->first();
        if (!$identity) {
            $identity = UserFirebirdIdentity::where('firebird_user_clave', (int)$usuario->CLAVE)->first();
        }
        if (!$identity) return null;

        // ── Tipo de usuario ──
        $esEmpleado  = $identity->firebird_tb_clave   !== null;
        $esCliente   = $identity->firebird_clie_clave !== null;
        $esVendedor  = $identity->firebird_vend_clave !== null;
        $esProveedor = $identity->firebird_prov_clave !== null;

        $tbRow   = null;
        $clieRow = null;
        $vendRow = null;
        $provRow = null;

        // =====================================================
        // 🏢 EMPLEADO: TB desde NOI
        // =====================================================
        if ($esEmpleado) {
            try {
                $tbClave     = is_string($identity->firebird_tb_clave)
                    ? trim($identity->firebird_tb_clave)
                    : $identity->firebird_tb_clave;
                $empresaNoi  = $identity->firebird_empresa ?? '04';
                $firebirdNoi = new FirebirdEmpresaManualService($empresaNoi, 'SRVNOI');

                $tb    = $firebirdNoi->getOperationalTable('TB')
                    ->keyBy(fn($row) => trim((string)$row->CLAVE));
                $tbRow = $tb[$tbClave] ?? null;
            } catch (\Throwable $e) {
                Log::error('UserService::me TB error', ['error' => $e->getMessage()]);
            }
        }

        // =====================================================
        // 🛒 CLIENTE: CLIE03
        // =====================================================
        if ($esCliente && $identity->firebird_clie_clave) {
            try {
                $clieRow = $this->firebirdService->getProductionConnection()
                    ->selectOne("SELECT * FROM CLIE03 WHERE CLAVE = ?", [$identity->firebird_clie_clave]);
            } catch (\Throwable $e) {
                Log::error('UserService::me CLIE03 error', ['error' => $e->getMessage()]);
            }
        }

        // =====================================================
        // 🧑‍💼 VENDEDOR: VEND03
        // =====================================================
        if ($esVendedor && $identity->firebird_vend_clave) {
            try {
                $vendRow = $this->firebirdService->getProductionConnection()
                    ->selectOne("SELECT * FROM VEND03 WHERE CVE_VEND = ?", [$identity->firebird_vend_clave]);
            } catch (\Throwable $e) {
                Log::error('UserService::me VEND03 error', ['error' => $e->getMessage()]);
            }
        }

        // =====================================================
        // 📦 PROVEEDOR: PROV03
        // =====================================================
        if ($esProveedor && $identity->firebird_prov_clave) {
            try {
                $provRow = $this->firebirdService->getProductionConnection()
                    ->selectOne("SELECT * FROM PROV03 WHERE CLAVE = ?", [$identity->firebird_prov_clave]);
            } catch (\Throwable $e) {
                Log::error('UserService::me PROV03 error', ['error' => $e->getMessage()]);
            }
        }

        return [
            'user' => [
                'name'               => $usuario->NOMBRE ?? null,
                'tipo_usuario'       => $esEmpleado  ? 'empleado'
                    : ($esCliente  ? 'cliente'
                        : ($esVendedor ? 'vendedor'
                            : ($esProveedor ? 'proveedor' : null))),
                'TB'                 => $tbRow,
                'CLIE'               => $clieRow,
                'VEND'               => $vendRow,
                'PROV'               => $provRow,
                'firebird_tb_clave'  => $identity->firebird_tb_clave   ?? null,
                'firebird_clie_clave' => $identity->firebird_clie_clave ?? null,
                'firebird_vend_clave' => $identity->firebird_vend_clave ?? null,
                'firebird_user_id'   => (int) $usuario->ID,
            ]
        ];
    }
}