<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name') }} - @yield('title', 'Payment Portal')</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance
    <x-turnstile.scripts />
</head>
<body class="min-h-screen bg-zinc-50 dark:bg-zinc-900">
    
    <flux:toast position="top right" />
    
    <div class="container mx-auto px-4 py-8 max-w-5xl">
        @yield('content')
        {{ $slot ?? '' }}
    </div>
    
    @fluxScripts
    @stack('scripts')
</body>
</html>
