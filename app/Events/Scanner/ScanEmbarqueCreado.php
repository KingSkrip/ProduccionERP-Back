<?php

namespace App\Events\Scanner;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class ScanEmbarqueCreado implements ShouldBroadcastNow 
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $codigo,
        public int    $codigoEnt,
        public string $fechaYHora,
        public int    $procesado
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('scanner-embarques');
    }

    public function broadcastAs(): string
    {
        return 'scan.creado';
    }
}