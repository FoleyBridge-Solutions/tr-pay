<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name') }} - @yield('title', 'Admin')</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600&display=swap" rel="stylesheet" />
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance
</head>
<body class="min-h-screen bg-white dark:bg-zinc-800 antialiased">
    
    <flux:sidebar sticky collapsible="mobile" class="bg-zinc-50 dark:bg-zinc-900 border-r border-zinc-200 dark:border-zinc-700">
        <flux:sidebar.header>
            <flux:sidebar.brand
                href="{{ route('admin.dashboard') }}"
                name="{{ config('app.name') }}"
                class="font-semibold"
            />
            <flux:sidebar.collapse class="lg:hidden" />
        </flux:sidebar.header>

        <flux:sidebar.nav>
            <flux:sidebar.item 
                icon="home" 
                href="{{ route('admin.dashboard') }}" 
                :current="request()->routeIs('admin.dashboard')"
            >
                Dashboard
            </flux:sidebar.item>
            
            <flux:sidebar.item 
                icon="credit-card" 
                href="{{ route('admin.payments') }}" 
                :current="request()->routeIs('admin.payments*')"
            >
                Payments
            </flux:sidebar.item>
            
            <flux:sidebar.item 
                icon="calendar" 
                href="{{ route('admin.payment-plans') }}" 
                :current="request()->routeIs('admin.payment-plans*')"
            >
                Payment Plans
            </flux:sidebar.item>
            
            <flux:sidebar.item 
                icon="arrow-path" 
                href="{{ route('admin.recurring-payments') }}" 
                :current="request()->routeIs('admin.recurring-payments*')"
            >
                Recurring Payments
            </flux:sidebar.item>
            
            <flux:sidebar.item 
                icon="users" 
                href="{{ route('admin.clients') }}" 
                :current="request()->routeIs('admin.clients*')"
            >
                Clients
            </flux:sidebar.item>
            
            <flux:sidebar.item 
                icon="user-circle" 
                href="{{ route('admin.users') }}" 
                :current="request()->routeIs('admin.users*')"
            >
                Users
            </flux:sidebar.item>
            
            <flux:sidebar.item 
                icon="clipboard-document-list" 
                href="{{ route('admin.activity-log') }}" 
                :current="request()->routeIs('admin.activity-log*')"
            >
                Activity Log
            </flux:sidebar.item>

            <flux:sidebar.item 
                icon="building-library" 
                href="{{ route('admin.ach.batches.index') }}" 
                :current="request()->routeIs('admin.ach.*')"
            >
                ACH Batches
            </flux:sidebar.item>

            <flux:sidebar.item 
                icon="arrow-uturn-left" 
                href="{{ route('admin.ach.returns.index') }}" 
                :current="request()->routeIs('admin.ach.returns*')"
            >
                ACH Returns
            </flux:sidebar.item>
        </flux:sidebar.nav>

        <flux:sidebar.spacer />

        <flux:sidebar.nav>
            <flux:dropdown position="top" align="start" class="max-lg:hidden">
                <flux:sidebar.profile name="{{ Auth::user()->name ?? 'Admin' }}" />
                
                <flux:menu>
                    <flux:menu.item icon="arrow-right-start-on-rectangle" href="{{ route('admin.logout') }}"
                        onclick="event.preventDefault(); document.getElementById('logout-form').submit();">
                        Logout
                    </flux:menu.item>
                </flux:menu>
            </flux:dropdown>
        </flux:sidebar.nav>
    </flux:sidebar>
    
    {{-- Mobile header --}}
    <flux:header class="lg:hidden">
        <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />
        
        <flux:spacer />
        
        <flux:dropdown position="top" align="end">
            <flux:profile name="{{ Auth::user()->name ?? 'Admin' }}" />
            
            <flux:menu>
                <flux:menu.item icon="arrow-right-start-on-rectangle" href="{{ route('admin.logout') }}"
                    onclick="event.preventDefault(); document.getElementById('logout-form').submit();">
                    Logout
                </flux:menu.item>
            </flux:menu>
        </flux:dropdown>
    </flux:header>

    <flux:main container>
        <flux:toast position="top right" />
        
        {{ $slot }}
    </flux:main>
    
    {{-- Logout form --}}
    <form id="logout-form" action="{{ route('admin.logout') }}" method="POST" class="hidden">
        @csrf
    </form>
    
    @fluxScripts
</body>
</html>
