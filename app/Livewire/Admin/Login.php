<?php

namespace App\Livewire\Admin;

use App\Models\AdminActivity;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Admin Login Component
 *
 * Handles admin user authentication.
 */
#[Layout('layouts::admin-guest')]
class Login extends Component
{
    #[Validate('required|email')]
    public string $email = '';

    #[Validate('required|min:8')]
    public string $password = '';

    public bool $remember = false;

    /**
     * Attempt to authenticate the user.
     */
    public function login(): void
    {
        $this->validate();

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password], $this->remember)) {
            $this->addError('email', 'The provided credentials do not match our records.');

            return;
        }

        $user = Auth::user();

        if (! $user->is_active) {
            Auth::logout();
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
