<?php

namespace App\Jobs;

use App\Services\Whatsapp\UltraMSGService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class EnviarMensajeWhatsappJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(
        private string $telefono,
        private string $mensaje,
    ) {}

    public function handle(): void
    {
        try {
            $whatsapp = new UltraMSGService();
            $resultado = $whatsapp->sendMessage($this->telefono, $this->mensaje);

            Log::info('✅ WhatsApp Job enviado', [
                'telefono'  => $this->telefono,
                'resultado' => $resultado,
            ]);
        } catch (\Throwable $e) {
            Log::error('❌ WhatsApp Job falló', [
                'telefono' => $this->telefono,
                'error'    => $e->getMessage(),
            ]);
            throw $e; // para que Laravel reintente
        }
    }
}