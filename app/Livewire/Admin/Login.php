<?php

namespace App\Livewire\Admin;

use App\Models\AdminActivity;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;
use RyanChandler\LaravelCloudflareTurnstile\Rules\Turnstile;

/**
 * Admin Login Component
 *
 * Handles admin user authentication with Cloudflare Turnstile bot protection.
 */
#[Layout('layouts::admin-guest')]
class Login extends Component
{
    #[Validate('required|email')]
    public string $email = '';

    #[Validate('required|min:8')]
    public string $password = '';

    public bool $remember = false;

    public string $turnstileToken = '';

    /**
     * Attempt to authenticate the user.
     */
    public function login(): void
    {
        $this->validate([
            'email' => 'required|email',
            'password' => 'required|min:8',
            'turnstileToken' => ['required', new Turnstile],
        ], [
            'turnstileToken.required' => 'Please complete the security check.',
        ]);

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password], $this->remember)) {
            $this->reset('turnstileToken');
            $this->addError('email', 'The provided credentials do not match our records.');

            return;
        }

        $user = Auth::user();

        if (! $user->is_active) {
            Auth::logout();
            $this->reset('turnstileToken');
            $this->addError('email', 'Your account has been deactivated.');

            return;
        }

        // Record login time
        $user->recordLogin();

        // Log the activity
        AdminActivity::logLogin("Admin login: {$user->email}");

        session()->regenerate();

        $this->redirect(route('admin.dashboard'));
    }

    /**
     * Log the user out.
     */
    public function logout(): void
    {
        // Log the activity before logging out
        if (Auth::check()) {
            AdminActivity::logLogout('Admin logout: '.Auth::user()->email);
        }

        Auth::logout();
        session()->invalidate();
        session()->regenerateToken();

        $this->redirect(route('admin.login'));
    }

    public function render()
    {
        return view('livewire.admin.login');
    }
}
