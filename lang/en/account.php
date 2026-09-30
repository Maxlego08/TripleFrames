<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Domaine `account` : Fortify, profil, comptes liés, avatars
    |--------------------------------------------------------------------------
    |
    | Domaine normatif de la spec 05. Couvre les écrans réellement présents
    | dans le dépôt : connexion, inscription, mot de passe, vérification
    | d’adresse, 2FA, passkeys, profil, sécurité, apparence, suppression de
    | compte. Les comptes liés (Discord, Google) et les avatars n’ont pas
    | encore d’écran : leurs clés naîtront avec eux (spec 40).
    |
    | `title` = titre de document, `heading` = titre affiché, `description` =
    | sous-titre.
    |
    */

    'fields' => [
        'current_password' => 'Current password',
        'email' => 'Email address',
        'email_placeholder' => 'email@example.com',
        'name' => 'Name',
        'name_placeholder' => 'Full name',
        'new_password' => 'New password',
        'password' => 'Password',
        'password_confirmation' => 'Confirm password',
        'password_hide' => 'Hide password',
        'password_show' => 'Show password',
    ],

    'settings' => [
        'description' => 'Manage your profile and account settings',
        'heading' => 'Settings',
        'nav' => [
            'appearance' => 'Appearance',
            'profile' => 'Profile',
            'security' => 'Security',
        ],
        'title' => 'Settings',
    ],

    'login' => [
        'description' => 'Enter your email and password below to log in',
        'forgot' => 'Forgot your password?',
        'heading' => 'Log in to your account',
        'no_account' => 'Don’t have an account?',
        'remember' => 'Remember me',
        'sign_up' => 'Sign up',
        'submit' => 'Log in',
        'title' => 'Log in',
    ],

    'register' => [
        'description' => 'Enter your details below to create your account',
        'has_account' => 'Already have an account?',
        'heading' => 'Create an account',
        'sign_in' => 'Log in',
        'submit' => 'Create account',
        'title' => 'Register',
    ],

    'forgot_password' => [
        'description' => 'Enter your email to receive a password reset link',
        'heading' => 'Forgot password',
        'return_prefix' => 'Or, return to',
        'return_link' => 'log in',
        'submit' => 'Email password reset link',
        'title' => 'Forgot password',
    ],

    'reset_password' => [
        'description' => 'Please enter your new password below',
        'heading' => 'Reset password',
        'submit' => 'Reset password',
        'title' => 'Reset password',
    ],

    'confirm_password' => [
        'description' => 'This is a secure area of the application. Please confirm your password before continuing.',
        'heading' => 'Confirm password',
        'passkey' => [
            'loading' => 'Confirming…',
            'separator' => 'Or confirm with password',
            'submit' => 'Confirm with passkey',
        ],
        'submit' => 'Confirm password',
        'title' => 'Confirm password',
    ],

    'verify_email' => [
        'description' => 'Please verify your email address by clicking on the link we just emailed to you.',
        'heading' => 'Email verification',
        'log_out' => 'Log out',
        'sent' => 'A new verification link has been sent to the email address you provided during registration.',
        'submit' => 'Resend verification email',
        'title' => 'Email verification',
    ],

    'two_factor' => [
        'challenge' => [
            'code' => [
                'description' => 'Enter the authentication code provided by your authenticator application.',
                'heading' => 'Authentication code',
                'toggle' => 'login using a recovery code',
            ],
            'recovery' => [
                'description' => 'Please confirm access to your account by entering one of your emergency recovery codes.',
                'heading' => 'Recovery code',
                'placeholder' => 'Enter recovery code',
                'toggle' => 'login using an authentication code',
            ],
            'submit' => 'Continue',
            'title' => 'Two-factor authentication',
            'toggle_prefix' => 'or you can',
        ],
        'errors' => [
            'qr_code' => 'The QR code could not be loaded.',
            'recovery_codes' => 'The recovery codes could not be loaded.',
            'setup_key' => 'The setup key could not be loaded.',
        ],
        'manage' => [
            'description' => 'Manage your two-factor authentication settings',
            'disable' => 'Disable 2FA',
            'disabled_hint' => 'When you enable two-factor authentication, you will be prompted for a secure pin during login. This pin can be retrieved from a TOTP-supported application on your phone.',
            'enable' => 'Enable 2FA',
            'enabled_hint' => 'You will be prompted for a secure, random pin during login, which you can retrieve from the TOTP-supported application on your phone.',
            'heading' => 'Two-factor authentication',
            'setup' => 'Continue setup',
        ],
        'setup' => [
            'code_placeholder' => 'Enter the 6-digit code',
            'confirm' => 'Confirm',
            'done' => [
                'description' => 'Two-factor authentication is now enabled. Scan the QR code or enter the setup key in your authenticator app.',
                'heading' => 'Two-factor authentication enabled',
                'submit' => 'Close',
            ],
            'manual_entry' => 'or enter the setup key manually',
            'scan' => [
                'description' => 'To finish enabling two-factor authentication, scan the QR code or enter the setup key in your authenticator app',
                'heading' => 'Enable two-factor authentication',
                'submit' => 'Continue',
            ],
            'verify' => [
                'description' => 'Enter the 6-digit code from your authenticator app',
                'heading' => 'Verify authentication code',
                'submit' => 'Continue',
            ],
        ],
        'recovery_codes' => [
            'description' => 'Recovery codes let you regain access if you lose your 2FA device.',
            'heading' => 'Recovery codes',
            'hide' => 'Hide recovery codes',
            'loading' => 'Loading recovery codes',
            'regenerate' => 'Regenerate codes',
            'single_use' => 'Each recovery code can be used once to access your account and will be removed after use.',
            'view' => 'View recovery codes',
        ],
    ],

    'passkeys' => [
        'added' => 'Added :date',
        'cancel' => 'Cancel',
        'description' => 'Manage your passkeys for passwordless sign-in',
        'empty' => 'No passkeys yet',
        'empty_hint' => 'Add a passkey to sign in without a password',
        'heading' => 'Passkeys',
        'last_used' => 'Last used :date',
        'name' => 'Passkey name',
        'name_default' => ':browser on :os',
        'name_hint' => 'A name helps you identify this passkey later.',
        'name_placeholder' => 'e.g., MacBook Pro, iPhone',
        'register' => 'Add passkey',
        'registering' => 'Registering…',
        'remove' => 'Remove passkey',
        'remove_confirm' => 'Are you sure you want to remove the passkey “:name”?',
        'removing' => 'Removing…',
        'submit' => 'Register passkey',
        'unsupported' => 'Passkeys are not supported in this browser.',
        'verify' => [
            'loading' => 'Authenticating…',
            'separator' => 'Or continue with email',
            'submit' => 'Sign in with a passkey',
        ],
    ],

    'profile' => [
        'description' => 'Update your name and email address',
        'heading' => 'Profile',
        'submit' => 'Save',
        'title' => 'Profile settings',
        'verification_sent' => 'A new verification link has been sent to your email address.',
        'verify_link' => 'Click here to re-send the verification email.',
        'unverified' => 'Your email address is unverified.',
    ],

    'security' => [
        'description' => 'Ensure your account is using a long, random password to stay secure',
        'heading' => 'Update password',
        'submit' => 'Save',
        'title' => 'Security settings',
    ],

    'appearance' => [
        'dark' => 'Dark',
        'description' => 'Update the appearance settings for your account',
        'heading' => 'Appearance settings',
        'light' => 'Light',
        'system' => 'System',
        'title' => 'Appearance settings',
    ],

    'delete_account' => [
        'cancel' => 'Cancel',
        'confirm' => 'Delete account',
        'description' => 'Anonymise your account and delete your personal data',
        'dialog_description' => 'Your account will be anonymised: email address, password, linked accounts, saved configurations and photo are deleted, and your name is replaced by a neutral label. Games already played remain, without your name, in other players’ history until they are erased after :months months.',
        'dialog_heading' => 'Are you sure you want to delete your account?',
        'heading' => 'Delete account',
        'submit' => 'Delete account',
        'warning' => 'Warning',
        'warning_hint' => 'Please proceed with caution, this cannot be undone.',
    ],

];
