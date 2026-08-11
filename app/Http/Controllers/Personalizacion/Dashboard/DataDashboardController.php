<?php

namespace App\Http\Controllers\Personalizacion\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Resources\Checador\ChecadorAccessQrCodeResource;
use App\Http\Resources\UsuarioResource;

use App\Models\Firebird\Users;
use App\Models\UserFirebirdIdentity;
use App\Models\UserPuesto;
use App\Services\Checador\ChecadorQrService;
use App\Services\FirebirdConnectionService;
use App\Services\FirebirdEmpresaManualService;


use Exception;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
use UnexpectedValueException;

class DataDashboardController extends Controller
{
    private $jwtSecret;
    private $jwtAlgorithm = 'HS256';
    protected FirebirdConnectionService $firebirdService;
    protected ChecadorQrService $qrService;

    public function __construct(
        FirebirdConnectionService $firebirdService,
        ChecadorQrService $qrService
    ) {
        $this->jwtSecret = config('jwt.secret');
        $this->firebirdService = $firebirdService;
        $this->qrService = $qrService;
    }

    public function me(Request $request)
    {
        try {
            $token = $request->bearerToken();
            if (!$token) {
                return response()->json(['message' => 'Token requerido'], 401);
            }

            $decoded = JWT::decode(
                $token,
                new Key($this->jwtSecret, 'HS256')
            );

            if (!isset($decoded->sub) || !ctype_digit((string)$decoded->sub)) {
                return response()->json(['message' => 'Token inválido (sub inválido)'], 401);
            }

            if (!isset($decoded->exp) || $decoded->exp < time()) {
                return response()->json(['message' => 'Token expirado'], 401);
            }

            if (!isset($decoded->iat) || $decoded->iat > time()) {
                return response()->json(['message' => 'Token no válido aún'], 401);
            }

            $sub = (int) $decoded->sub;

            if (!$sub) {
                return response()->json(['message' => 'Token inválido (sin sub)'], 401);
            }

            $usuario = Users::find($sub);

            if (!$usuario) {
                $usuarioPorClave = Users::where('CLAVE', $sub)->first();

                Log::warning('🧯 ME_FALLBACK_BY_CLAVE', [
                    'sub' => $sub,
                    'found_by_clave' => (bool) $usuarioPorClave,
                    'usuario_id' => $usuarioPorClave->ID ?? null,
                    'usuario_clave' => $usuarioPorClave->CLAVE ?? null,
                ]);

                $usuario = $usuarioPorClave;
            }

            if (!$usuario) {
                return response()->json(['message' => 'Usuario no encontrado en Firebird'], 404);
            }

            $identity = UserFirebirdIdentity::where('firebird_user_clave', (int)$usuario->ID)->first();

            if (!$identity) {
                $identityLegacy = UserFirebirdIdentity::where('firebird_user_clave', (int)$usuario->CLAVE)->first();

                Log::warning('🧯 ME_IDENTITY_LEGACY_FALLBACK', [
                    'usuarios_clave' => $usuario->CLAVE,
                    'identity_id' => $identityLegacy->id ?? null,
                ]);

                $identity = $identityLegacy;
            }

            if (!$identity) {
                return response()->json(['message' => 'Identidad de usuario no configurada'], 404);
            }

            $esEmpleado = $identity->firebird_tb_clave !== null;
            $esCliente = $identity->firebird_clie_clave !== null;
            $esVendedor = $identity->firebird_vend_clave !== null;
            $esProveedor = $identity->firebird_prov_clave !== null;

            $roles = $identity->roles()->get();
            $turnoActivo = null;

            if ($esEmpleado) {
                $turnoActivo = $identity->turnoActivo()
                    ->with(['turno.turnoDias', 'status'])
                    ->first();
            }

            // 🎫 QR fijo de checador (mismo token siempre, mientras esté activo)
            $qrData = null;

            if (!$identity->excluir_checador) {
                try {
                    $qr = $this->qrService->generar($identity->id);
                    $qrData = (new ChecadorAccessQrCodeResource($qr))->resolve();
                } catch (\Throwable $e) {
                    Log::warning('⚠️ ME_QR_NO_GENERADO', [
                        'identity_id' => $identity->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $departamentos = collect();
            $slRow = null;
            $vcRow = null;
            $hvcRow = null;
            $mfRow = null;
            $acRows = collect();
            $tbRow = null;
            $deptoRow = null;
            $puestoRow = null;
            $clieRow = null;

            if ($esEmpleado) {
                $tbClave = $identity->firebird_tb_clave;
                $tbClaveNorm = is_string($tbClave) ? trim($tbClave) : $tbClave;
                $empresaNoi = $identity->firebird_empresa ?? '04';

                try {
                    $firebirdNoi = new FirebirdEmpresaManualService($empresaNoi, 'SRVNOI');

                    // 🔥 DEPTOS se pide UNA sola vez y se reutiliza para el lookup de depto del empleado
                    $departamentos = $firebirdNoi->getMasterTable('DEPTOS')->keyBy(fn($row) => trim((string)$row->CLAVE));

                    // 🔥 Filtramos por clave DIRECTO en Firebird (WHERE) en vez de traer la tabla completa y hacer keyBy en PHP
                    $slRow  = $firebirdNoi->getOperationalRowByClave('SL', $tbClaveNorm, 'CLAVE_TRAB');
                    $vcRow  = $firebirdNoi->getOperationalRowByClave('VC', $tbClaveNorm, 'CLAVE_TRAB');
                    $hvcRow = $firebirdNoi->getMasterRowByClave('HISTVAC', $tbClaveNorm, 'CVETRAB');
                    $mfRow  = $firebirdNoi->getOperationalRowByClave('MF', $tbClaveNorm, 'CLAVE_TRAB');
                    $acRows = $firebirdNoi->getOperationalRowsByClave('AC', $tbClaveNorm, 'CLAVE_TRAB');
                    $tbRow  = $firebirdNoi->getOperationalRowByClave('TB', $tbClaveNorm, 'CLAVE');

                    // 🚫 Bloquear sesión si el empleado está dado de baja (STATUS = 'B')
                    // if ($tbRow && isset($tbRow->STATUS) && trim((string)$tbRow->STATUS) === 'B') {
                    //     return response()->json([
                    //         'message' => 'Tu usuario ha sido dado de baja y no puedes ingresar.'
                    //     ], 403);
                    // }

                    if ($tbRow) {
                        // 🏢 DEPTO (usa $departamentos ya cargado arriba, sin volver a pedirlo a Firebird)
                        $deptoClave = isset($tbRow->DEPTO) ? trim((string)$tbRow->DEPTO) : null;

                        if ($deptoClave) {
                            $deptoRow = $departamentos[$deptoClave] ?? null;
                        }

                        // 👔 PUESTO
                        $puestoClave = isset($tbRow->PUESTO) ? trim((string)$tbRow->PUESTO) : null;

                        if ($puestoClave) {
                            try {
                                $puestos = $firebirdNoi->getMasterTable('PUESTOS')
                                    ->keyBy(fn($row) => trim((string)$row->CLAVE));
                                $puestoRow = $puestos[$puestoClave] ?? null;
                            } catch (\Throwable $e) {
                                Log::error('⚠️ ME_PUESTO_LOOKUP_ERROR', [
                                    'puesto_clave' => $puestoClave,
                                    'error'        => $e->getMessage(),
                                ]);
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    Log::error('⚠️ ME_EMPLEADO_NOI_ERROR', [
                        'empresaNoi' => $empresaNoi,
                        'tbClave' => $tbClaveNorm,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if ($esCliente) {
                $clieClave = $identity->firebird_clie_clave;

                if ($clieClave) {
                    try {
                        $connection = $this->firebirdService->getProductionConnection();

                        $clieRow = $connection->selectOne(
                            "SELECT * FROM CLIE03 WHERE CLAVE = ?",
                            [$clieClave]
                        );
                    } catch (\Throwable $e) {
                        Log::error('⚠️ ME_CLIENTE_DATA_ERROR', [
                            'clie_clave' => $clieClave,
                            'error' => $e->getMessage(),
                        ]);
                    }
                } else {
                    Log::warning('⚠️ ME_CLIENTE_NO_CLIE_CLAVE', [
                        'identity_id' => $identity->id,
                    ]);
                }
            }

            $vendRow = null;

            if ($esVendedor) {
                $vendClave = $identity->firebird_vend_clave;

                if ($vendClave) {
                    try {
                        $connection = $this->firebirdService->getProductionConnection();
                        $vendRow = $connection->selectOne(
                            "SELECT * FROM VEND03 WHERE CVE_VEND = ?",
                            [$vendClave]
                        );
                    } catch (\Throwable $e) {
                        Log::error('⚠️ ME_VENDEDOR_DATA_ERROR', [
                            'vend_clave' => $vendClave,
                            'error'      => $e->getMessage(),
                        ]);
                    }
                } else {
                    Log::warning('⚠️ ME_VENDEDOR_NO_VEND_CLAVE', [
                        'identity_id' => $identity->id,
                    ]);
                }
            }

            $provRow = null;

            if ($esProveedor) {
                $provClave = $identity->firebird_prov_clave;

                if ($provClave) {
                    try {
                        $connection = $this->firebirdService->getProductionConnection();
                        $provRow = $connection->selectOne(
                            "SELECT * FROM PROV03 WHERE CLAVE = ?",
                            [$provClave]
                        );
                    } catch (\Throwable $e) {
                        Log::error('⚠️ ME_PROVEEDOR_DATA_ERROR', [
                            'prov_clave' => $provClave,
                            'error'      => $e->getMessage(),
                        ]);
                    }
                } else {
                    Log::warning('⚠️ ME_PROVEEDOR_NO_PROV_CLAVE', [
                        'identity_id' => $identity->id,
                    ]);
                }
            }

            $userPuesto = UserPuesto::with([
                'puesto',
                'area',
                'jefe.firebirdUser',
            ])
                ->where('user_firebird_identity_id', $identity->id)
                ->where('activo', true)
                ->first();

            return response()->json([
                'user' => new UsuarioResource($usuario, [
                    'user_puesto' => $userPuesto,
                    'departamentos'       => $departamentos,
                    'sl'                  => $slRow,
                    'vacaciones'          => $vcRow,
                    'historialvacaciones' => $hvcRow,
                    'faltas'              => $mfRow,
                    'acumuladosperiodos'  => $acRows,
                    'roles'               => $roles,
                    'TB'                  => $tbRow,
                    'CLIE'                => $clieRow,
                    'VEND'                => $vendRow,
                    'qr'                  => $qrData,
                    'firebird_tb_clave'   => $identity->firebird_tb_clave ?? null,
                    'firebird_clie_clave' => $identity->firebird_clie_clave ?? null,
                    'firebird_vend_clave' => $identity->firebird_vend_clave ?? null,
                    'turnoActivo'         => $turnoActivo,
                    'firebird_user_id'    => (int)$usuario->ID,
                    'usuarios_clave'      => (string)$usuario->CLAVE,
                    'identity_id'         => $identity->id,
                    'empresaNoi'          => $identity->firebird_empresa ?? null,
                    'tipo_usuario' => $esEmpleado ? 'empleado' : ($esCliente ? 'cliente' : ($esVendedor ? 'vendedor' : ($esProveedor ? 'proveedor' : null))),
                    'DEPTO_NOI'  => $deptoRow,
                    'PUESTO_NOI' => $puestoRow,
                    'PROV'                => $provRow,
                    'firebird_prov_clave' => $identity->firebird_prov_clave ?? null,
                ])
            ], 200);
        } catch (SignatureInvalidException $e) {
            Log::warning('Firma inválida', ['token_prefix' => substr($token ?? '', 0, 20)]);
            return response()->json(['message' => 'Token inválido'], 401);
        } catch (ExpiredException $e) {
            Log::warning('🔴 ME_TOKEN_EXPIRED', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'El token ha expirado'], 401);
        } catch (BeforeValidException $e) {
            return response()->json(['message' => 'Token no válido aún'], 401);
        } catch (UnexpectedValueException $e) {
            Log::warning('JWT malformado o inválido', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Token inválido'], 401);
        } catch (\Throwable $e) {
            Log::error('🔴 ME_FATAL_ERROR', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Error de autenticación'
            ], 401);
        }
    }

    /**
     * Actualizar status del usuario
     */
    public function updateStatus(Request $request)
    {
        try {
            $request->validate([
                'status' => 'required|string',
            ]);
            $token = $request->bearerToken();
            if (! $token) {
                return response()->json(['message' => 'Token requerido'], 401);
            }
            $decoded = JWT::decode($token, new Key($this->jwtSecret, $this->jwtAlgorithm));
            $usuario = Users::find($decoded->sub);
            if (! $usuario) {
                return response()->json(['message' => 'Usuario no encontrado'], 404);
            }
            $usuario->status_id = $request->status;
            $usuario->save();
            return response()->json([
                'message' => 'Status actualizado',
                'user' => new UsuarioResource($usuario),
            ]);
        } catch (Exception $e) {
            Log::error('Error en updateStatus(): ' . $e->getMessage());

            return response()->json(['message' => 'Error al actualizar status'], 500);
        }
    }
}