<?php

return [
    'auth' => [
        'invalid_credentials' => 'Credenciales inválidas.',
        'unauthenticated' => 'No autenticado.',
        'password_reset_subject' => 'Restablecer contraseña',
        'password_reset_email' => 'Para restablecer tu contraseña, abre este enlace: :url',
        'password_reset_email_intro' => 'Recibimos una solicitud para restablecer tu contraseña.',
        'password_reset_email_cta' => 'Restablecer contraseña',
        'password_reset_email_ignore' => 'Si no solicitaste este cambio, puedes ignorar este mensaje.',
        'password_reset_sent' => 'Si el email existe, se envió un enlace de restablecimiento.',
        'password_reset_invalid' => 'El enlace de restablecimiento no es válido.',
        'password_reset_expired' => 'El enlace de restablecimiento ha expirado.',
        'password_reset_success' => 'Contraseña actualizada.',
        'two_factor_subject' => 'Código de acceso',
        'two_factor_email' => 'Tu código de acceso es: :code',
        'two_factor_invalid' => 'El código no es válido.',
        'two_factor_expired' => 'El código ha expirado.',
        'two_factor_locked' => 'Demasiados intentos. Solicita un nuevo código.',
        'two_factor_resent' => 'Código reenviado.',
        'two_factor_no_test_emails' => 'No hay correos de prueba configurados.',
    ],
    'tenant' => [
        'missing' => 'No se pudo determinar el tenant.',
        'languages_updated' => 'Idiomas del tenant actualizados.',
    ],
    'user' => [
        'language_not_enabled' => 'El idioma no está habilitado para el tenant.',
        'language_updated' => 'Idioma del usuario actualizado.',
    ],
];
