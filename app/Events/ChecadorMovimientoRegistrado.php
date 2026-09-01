<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChecadorMovimientoRegistrado implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public int $identityId,
        public ?string $nombre,
        public ?string $foto,
        public string $tipo,
        public string $hora,
        public ?string $firebirdEmpresa,
        public string $metodo,
    ) {}

    /**
     * Canal donde se enviará el evento.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('guardia'),
        ];
    }


    /**
     * Nombre que recibirá Angular.
     */
    public function broadcastAs(): string
    {
        return 'checada.registrada';
    }

    /**
     * Datos enviados al frontend.
     */
    public function broadcastWith(): array
    {
        return [
            'identity_id' => $this->identityId,
            'nombre' => $this->nombre,
            'foto' => $this->foto,
            'tipo' => $this->tipo,
            'hora' => $this->hora,
            'firebird_empresa' => $this->firebirdEmpresa,
            'metodo' => $this->metodo,
        ];
    }
}