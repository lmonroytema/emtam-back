<?php

return [
    'auth' => [
        'invalid_credentials' => 'Invalid credentials.',
        'unauthenticated' => 'Unauthenticated.',
        'password_reset_subject' => 'Reset password',
        'password_reset_email' => 'To reset your password, open this link: :url',
        'password_reset_email_intro' => 'We received a request to reset your password.',
        'password_reset_email_cta' => 'Reset password',
        'password_reset_email_ignore' => 'If you did not request this, you can ignore this message.',
        'password_reset_sent' => 'If the email exists, a reset link was sent.',
        'password_reset_invalid' => 'The reset link is invalid.',
        'password_reset_expired' => 'The reset link has expired.',
        'password_reset_success' => 'Password updated.',
        'two_factor_subject' => 'Access code',
        'two_factor_email' => 'Your access code is: :code',
        'two_factor_invalid' => 'The code is invalid.',
        'two_factor_expired' => 'The code has expired.',
        'two_factor_locked' => 'Too many attempts. Request a new code.',
        'two_factor_resent' => 'Code resent.',
        'two_factor_no_test_emails' => 'No test emails configured.',
    ],
    'tenant' => [
        'missing' => 'Unable to determine tenant.',
        'languages_updated' => 'Tenant languages updated.',
    ],
    'user' => [
        'language_not_enabled' => 'Language is not enabled for the tenant.',
        'language_updated' => 'User language updated.',
    ],
];
