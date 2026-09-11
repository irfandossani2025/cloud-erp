<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class MakeAdmin extends Command
{
    protected $signature = 'erp:make-admin {email} {name} {--password=}';

    protected $description = 'Create or promote a staff account to Cloud ERP administrator';

    public function handle(): int
    {
        $email = $this->argument('email');
        $name = $this->argument('name');
        $validator = Validator::make(['email' => $email], ['email' => 'required|email']);
        if ($validator->fails()) {
            $this->error($validator->errors()->first());
            return self::FAILURE;
        }
        $password = $this->option('password') ?: Str::password(16);
        $user = DB::transaction(function () use ($email, $name, $password) {
            $user = User::where('email', $email)->first();
            if ($user) {
                if (!$user->agent_id) {
                    $agentId = (string) Str::uuid();
                    DB::table('agents')->insert(['id' => $agentId, 'name' => $name]);
                    $user->agent_id = $agentId;
                }
                $user->is_admin = true;
                $user->save();
                return $user;
            }
            $agentId = (string) Str::uuid();
            DB::table('agents')->insert(['id' => $agentId, 'name' => $name]);
            return User::create([
                'name' => $name, 'email' => $email, 'password' => $password,
                'is_admin' => true, 'agent_id' => $agentId,
            ]);
        });
        $this->info("Administrator ready: {$user->email}");
        if (!$this->option('password')) {
            $this->info("Generated password: {$password}");
        }
        return self::SUCCESS;
    }
}
