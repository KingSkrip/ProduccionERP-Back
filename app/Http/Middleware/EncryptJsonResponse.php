<?php
// app/Http/Middleware/EncryptJsonResponse.php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class EncryptJsonResponse
{
    /**
     * Rutas que se deben encriptar
     */
    private $encryptRoutes = [
        // Auth
        'api/auth/*',

        // Dashboard
        'api/dash/*',

        // Perfil
        'api/perfil',
        'api/perfil/*',

        // Super Admin
        'api/superadmin/*',

        // RH
        'api/rh/*',

        // Colaborador
        'api/colaborador/*',

        // Roles
        'api/roles/*',

        // Catálogos
        'api/catalogos/*',

        // RH Empresa Uno
        'api/rh/E_ONE/*',

        // Colaboradores (Vacaciones)
        'api/colaboradores/*',

        // Firebird (Pedidos)
        'api/firebird/*',

        // Reportes de Producción
        'api/reportes-produccion',
        'api/reportes-produccion/*',

        // Tasks
        'api/tasks',
        'api/tasks/*',

        //AL USERS
        'api/users/all',
        'api/users/all/*',

        // Mailbox
        'api/mailbox/*',

        //Estados de cuenta
        'api/estados-cuenta/*',



    ];

    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        $encryptEnabled = config('security.encrypt_json_response');

        if (
            $encryptEnabled &&
            str_contains($response->headers->get('Content-Type', ''), 'application/json') &&
            $this->shouldEncrypt($request->path())
        ) {
            $originalContent = $response->getContent();

            $encrypted = Crypt::encryptString($originalContent);

            $response->setContent(json_encode([
                'encrypted' => $encryptEnabled,
                'data' => $encrypted
            ]));
        }

        return $response;
    }

    /**
     * Verificar si la ruta debe encriptarse
     */
    private function shouldEncrypt(string $path): bool
    {
        foreach ($this->encryptRoutes as $pattern) {
            if (fnmatch($pattern, $path)) {
                return true;
            }
        }
        return false;
    }
}