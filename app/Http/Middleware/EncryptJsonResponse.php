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
    ];

    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        // Solo encriptar JSON responses
        if (
            str_contains($response->headers->get('Content-Type', ''), 'application/json') &&
            $this->shouldEncrypt($request->path())
        ) {
            $originalContent = $response->getContent();
            
            // 🔥 CAMBIO: Encriptar y retornar el payload completo de Laravel
            $encrypted = Crypt::encryptString($originalContent);
            
            // Retornar el formato que Angular espera
            $response->setContent(json_encode([
                'encrypted' => true,
                'data' => $encrypted  // ← Ya viene en formato Laravel nativo
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