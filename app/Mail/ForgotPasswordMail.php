<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ForgotPasswordMail extends Mailable
{
    public $token;
    public $email;
    public $user;

    public function __construct($token, $email, $user)
    {
        $this->token = $token;
        $this->email = $email;
        $this->user = $user;
    }

    public function build()
    {
        return $this->subject('Restablecimiento de contraseña')
            ->view('emails.forgot-password');
    }
}

