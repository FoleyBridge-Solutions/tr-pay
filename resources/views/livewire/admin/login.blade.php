<div class="w-full max-w-sm">
    <div class="text-center mb-8">
        <flux:heading size="xl">{{ config('app.name') }}</flux:heading>
        <flux:subheading>Admin Login</flux:subheading>
    </div>

    <flux:card class="p-6">
        <form wire:submit="login" class="space-y-6">
            @if (session('error'))
                <flux:callout variant="danger" icon="exclamation-triangle">
                    {{ session('error') }}
                </flux:callout>
            @endif

            <flux:field>
                <flux:label>Email</flux:label>
                <flux:input 
                    wire:model="email" 
                    type="email" 
                    placeholder="admin@example.com"
                    autofocus
                />
                <flux:error name="email" />
            </flux:field>

            <flux:field>
                <flux:label>Password</flux:label>
                <flux:input 
                    wire:model="password" 
                    type="password" 
                    placeholder="Enter your password"
                />
                <flux:error name="password" />
            </flux:field>

            <flux:checkbox wire:model="remember" label="Remember me" />

            <div>
                <x-turnstile wire:model="turnstileToken" data-action="login" data-theme="light" />
                <flux:error name="turnstileToken" />
            </div>

            <flux:button type="submit" variant="primary" class="w-full">
                Sign in
            </flux:button>
        </form>
    </flux:card>
</div>
