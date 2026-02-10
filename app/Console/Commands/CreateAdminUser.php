<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Create an admin user for the admin panel.
 *
 * Usage: php artisan admin:create
 */
class CreateAdminUser extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'admin:create
                            {--name= : The admin user\'s name}
                            {--email= : The admin user\'s email}
                            {--password= : The admin user\'s password}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create an admin user for the admin panel';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Creating a new admin user...');
        $this->newLine();

        // Get name
        $name = $this->option('name') ?? $this->ask('Name');
        if (empty($name)) {
            $this->error('Name is required.');

            return Command::FAILURE;
        }

        // Get email
        $email = $this->option('email') ?? $this->ask('Email');
        $validator = Validator::make(['email' => $email], [
            'email' => 'required|email|unique:users,email',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return Command::FAILURE;
        }

        // Get password
        $password = $this->option('password') ?? $this->secret('Password (min 8 characters)');
        if (strlen($password) < 8) {
            $this->error('Password must be at least 8 characters.');

            return Command::FAILURE;
        }

        // Confirm password if interactive
        if (! $this->option('password')) {
            $confirmPassword = $this->secret('Confirm Password');
            if ($password !== $confirmPassword) {
                $this->error('Passwords do not match.');

                return Command::FAILURE;
            }
        }

        // Create the user
        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password, // Will be hashed by cast
            'is_active' => true,
        ]);

        $this->newLine();
        $this->info('Admin user created successfully!');
        $this->table(
            ['ID', 'Name', 'Email'],
            [[$user->id, $user->name, $user->email]]
        );

        return Command::SUCCESS;
    }
}
