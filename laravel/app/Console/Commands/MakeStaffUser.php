<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class MakeStaffUser extends Command
{
    protected $signature = 'erp:make-staff {email} {name} {--password=} {--role=}';

    protected $description = 'Create a staff account with a sign-in login (plain agent, or --role=pricing/accounts)';

    public function handle(): int
    {
        $email = $this->argument('email');
        $name = $this->argument('name');
        $role = $this->option('role') ?: null;
        $validator = Validator::make(
            ['email' => $email, 'role' => $role],
            ['email' => 'required|email|unique:users,email', 'role' => 'nullable|in:pricing,accounts'],
        );
        if ($validator->fails()) {
            $this->error($validator->errors()->first());
            return self::FAILURE;
        }
        $password = $this->option('password') ?: Str::password(16);
        $agentId = (string) Str::uuid();
        DB::table('agents')->insert(['id' => $agentId, 'name' => $name]);
        User::create([
            'name' => $name, 'email' => $email, 'password' => $password,
            'is_admin' => false, 'agent_id' => $agentId, 'role' => $role,
        ]);
        $this->info("Staff account ready: {$email}".($role ? " (role: {$role})" : ''));
        if (!$this->option('password')) {
            $this->info("Generated password: {$password}");
        }
        return self::SUCCESS;
    }
}
