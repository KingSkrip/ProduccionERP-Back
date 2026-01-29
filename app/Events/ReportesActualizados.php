<?php
// app/Events/ReportesActualizados.php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ReportesActualizados implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $mensaje;
    public $datos;

    public function __construct($mensaje, $datos = null)
    {
        $this->mensaje = $mensaje;
        $this->datos = $datos;
    }

    /**
     * Canal donde se transmitirá el evento
     */
    public function broadcastOn(): Channel
    {
        return new Channel('reportes-produccion');
    }

    /**
     * Nombre del evento que se emitirá
     */
    public function broadcastAs(): string
    {
        return 'reportes.actualizados';
    }

    /**
     * Datos que se enviarán
     */
    public function broadcastWith(): array
    {
        return [
            'mensaje' => $this->mensaje,
            'datos' => $this->datos,
            'timestamp' => now()->toDateTimeString(),
        ];
    }
}