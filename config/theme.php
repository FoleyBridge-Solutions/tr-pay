<?php

// config/theme.php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Theme & Branding
    |--------------------------------------------------------------------------
    |
    | Configure the visual appearance of your application including
    | logos, favicons, and color schemes.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Logo Configuration
    |--------------------------------------------------------------------------
    |
    | Paths to your company logo files. Place logo files in the
    | public/images directory or specify a full URL.
    |
    */

    'logo_path' => env('APP_LOGO_PATH', '/images/logo.svg'),

    'logo_light' => env('APP_LOGO_LIGHT', env('APP_LOGO_PATH', '/images/logo-light.svg')),

    'logo_dark' => env('APP_LOGO_DARK', env('APP_LOGO_PATH', '/images/logo-dark.svg')),

    'logo_small' => env('APP_LOGO_SMALL', env('APP_LOGO_PATH', '/images/logo-small.svg')),

    'favicon_path' => env('APP_FAVICON_PATH', '/favicon.ico'),

    /*
    |--------------------------------------------------------------------------
    | Color Scheme
    |--------------------------------------------------------------------------
    |
    | Primary and accent colors for the application. These should be
    | valid CSS color values (hex, rgb, or Tailwind CSS color names).
    |
    */

    'primary_color' => env('THEME_PRIMARY_COLOR', '#18181b'), // zinc-900

    'accent_color' => env('THEME_ACCENT_COLOR', '#3b82f6'), // blue-500

    'success_color' => env('THEME_SUCCESS_COLOR', '#10b981'), // green-500

    'warning_color' => env('THEME_WARNING_COLOR', '#f59e0b'), // amber-500

    'danger_color' => env('THEME_DANGER_COLOR', '#ef4444'), // red-500

    /*
    |--------------------------------------------------------------------------
    | Layout Colors
    |--------------------------------------------------------------------------
    |
    | Tailwind CSS classes for layout elements. Use full class names.
    |
    */

    'navbar_bg' => env('THEME_NAVBAR_BG', 'bg-zinc-800'),

    'navbar_text' => env('THEME_NAVBAR_TEXT', 'text-white'),

    'footer_bg' => env('THEME_FOOTER_BG', 'bg-zinc-900'),

    'footer_text' => env('THEME_FOOTER_TEXT', 'text-zinc-400'),

    /*
    |--------------------------------------------------------------------------
    | Email Branding
    |--------------------------------------------------------------------------
    |
    | Logo URL for email templates (must be publicly accessible).
    |
    */

    'email_logo_url' => env('EMAIL_LOGO_URL', env('APP_URL', 'https://example.com') . '/images/logo.png'),

];
