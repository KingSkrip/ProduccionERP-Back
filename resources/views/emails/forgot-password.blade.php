<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Restablecer tu contraseña</title>
</head>
<body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background-color: #f5f5f5;">
    <table role="presentation" style="width: 100%; border-collapse: collapse; background-color: #f5f5f5;">
        <tr>
            <td align="center" style="padding: 40px 20px;">
                <!-- Main Container -->
                <table role="presentation" style="width: 100%; max-width: 600px; border-collapse: collapse; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 16px rgba(0,0,0,0.08);">
                    
                    <!-- Header con degradado -->
                    <tr>
                        <td style="padding: 48px 40px; text-align: center;
                            background: linear-gradient(135deg, #56CC9D 98%, #A8E6CF 2%);">
                            <h1 style="margin: 0; color: #ffffff; font-size: 26px; font-weight: 700; letter-spacing: -0.5px;">
                                {{ config('app.name') }}
                            </h1>
                            <p style="margin: 8px 0 0; color: rgba(255,255,255,0.9); font-size: 15px; font-weight: 500;">
                                Restablecimiento de Contraseña
                            </p>
                        </td>

                    </tr>
                    
                    <!-- Contenido Principal -->
                    <tr>
                        <td style="padding: 48px 40px;">
                            <!-- Saludo -->
                            <h2 style="margin: 0 0 8px; color: #1a1a1a; font-size: 24px; font-weight: 700;">¡Hola! 👋</h2>
                            
                            <p style="margin: 0 0 28px; color: #525252; font-size: 16px; line-height: 1.6;">
                                Has solicitado restablecer la contraseña de tu cuenta en {{ config('app.name') }}.
                            </p>
                            
                            <p style="margin: 0 0 32px; color: #525252; font-size: 16px; line-height: 1.6;">
                                Haz clic en el botón de abajo para crear una nueva contraseña segura. Este enlace expirará en <strong style="color: #1a1a1a;">60 minutos</strong> por razones de seguridad.
                            </p>
                            
                            <!-- Botón Principal -->
                            <table role="presentation" style="margin: 0 0 32px; border-collapse: collapse; width: 100%;">
                                <tr>
                                    <td align="center">
                                       <a href="{{ $url = config('app.frontend_url') . '/reset-password?token=' . $token . '&email=' . urlencode($user->email); }}"
                                        style="display: inline-block; padding: 16px 48px;
                                            background: linear-gradient(135deg, #56CC9D 92%, #A8E6CF 8%);
                                            color: #ffffff; text-decoration: none; font-size: 16px; font-weight: 600;
                                            border-radius: 8px; box-shadow: 0 4px 12px rgba(86, 204, 157, 0.35);
                                            transition: all 0.3s;">
                                            Restablecer contraseña
                                        </a>
                                    </td>
                                </tr>
                            </table>
                            
                            <!-- Alerta de Seguridad -->
                            <table role="presentation" style="width: 100%; border-collapse: collapse; background-color: #fef2f2; border-left: 4px solid #ef4444; border-radius: 6px;">
                                <tr>
                                    <td style="padding: 20px 24px;">
                                        <p style="margin: 0 0 8px; color: #991b1b; font-size: 15px; font-weight: 700;">
                                            ¿No solicitaste este cambio?
                                        </p>
                                        <p style="margin: 0; color: #7f1d1d; font-size: 14px; line-height: 1.5;">
                                            Si no fuiste tú quien pidió restablecer la contraseña, ignora este correo. Tu cuenta seguirá protegida y nadie podrá cambiar tu contraseña sin acceso a este enlace.
                                        </p>
                                    </td>
                                </tr>
                            </table>
                            
                            <!-- Despedida -->
                            <p style="margin: 32px 0 0; color: #525252; font-size: 15px; line-height: 1.6;">
                                Saludos,<br>
                                <strong style="color: #1a1a1a;">El equipo de {{ config('app.name') }}</strong>
                            </p>
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td style="padding: 32px 40px; background-color: #fafafa; border-top: 1px solid #e5e5e5;">
                            <p style="margin: 0 0 12px; color: #737373; font-size: 13px; line-height: 1.5; text-align: center;">
                                Tu seguridad es nuestra prioridad
                            </p>
                            <p style="margin: 0; color: #a3a3a3; font-size: 12px; line-height: 1.5; text-align: center;">
                                <strong>{{ config('app.name') }}</strong>
                            </p>
                           <p style="margin: 8px 0 0; color: #a3a3a3; font-size: 11px; line-height: 1.5; text-align: center;">
                                © {{ date('Y') }} Todos los derechos reservados.
                            </p>

                        </td>
                    </tr>
                    
                </table>
                
                <!-- Texto adicional fuera del contenedor -->
                <p style="margin: 24px 0 0; color: #a3a3a3; font-size: 12px; line-height: 1.5; text-align: center; max-width: 500px;">
                    Si tienes problemas con el botón, copia y pega este enlace en tu navegador:<br>
                    <span style="color: #3b82f6; word-break: break-all;">{{ $url = config('app.frontend_url') . '/reset-password?token=' . $token . '&email=' . urlencode($user->email); }}
</span>
                </p>
                
            </td>
        </tr>
    </table>
</body>
</html>