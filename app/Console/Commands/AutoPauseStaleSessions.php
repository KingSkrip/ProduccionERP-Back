<?php

namespace App\Console\Commands;

use App\Models\UserLoginSession;
use Illuminate\Console\Command;

class AutoPauseStaleSessions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sessions:auto-pause';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Pausa sesiones activas que no mandaron heartbeat recientemente';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $sesiones = UserLoginSession::where('status', 1)
            ->where('last_activity', '<', now()->subMinutes(2))
            ->get();

        $count = $sesiones->count();

        $sesiones->each->pausar();

        $this->info("Sesiones pausadas: {$count}");

        return self::SUCCESS;
    }
}