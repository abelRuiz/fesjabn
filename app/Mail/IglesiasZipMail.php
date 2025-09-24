<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class IglesiasZipMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $distrito;
    public string $iglesia;
    public string $bodyText;
    public string $zipPath;

    public function __construct(string $distrito, string $iglesia, string $bodyText, string $zipPath)
    {
        $this->distrito = $distrito;
        $this->iglesia  = $iglesia;
        $this->bodyText = $bodyText;
        $this->zipPath  = $zipPath;
    }

    public function build()
    {
        return $this->subject('Material del evento')
            ->markdown('emails.iglesia-zip')
            ->attach($this->zipPath, ['as' => 'material.zip', 'mime' => 'application/zip']);
    }
}
