<?php

namespace App\Console\Commands;

use App\Models\Inscrito;
use Illuminate\Support\Str;
use App\Mail\IglesiasZipMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class InscritosSendZips extends Command
{
    protected $signature = 'inscritos:send-zips
        {--path=barcodes : Carpeta base en storage/app donde están los zips}
        {--subject=Material del evento : Asunto del correo}
        {--body=Hola, adjuntamos los codigos de su iglesia. : Cuerpo del correo (texto plano)}
        {--from= : Dirección From opcional (por defecto config/mail.from)}
        {--name= : Nombre From opcional}
        {--dry-run : No envía, solo muestra qué se enviaría}
        {--sleep=2 : Segundos de espera entre correos (throttle)}';

    protected $description = 'Envía por email los ZIPs por iglesia a los directores (DISTINCT distrito, iglesia, email).';

    public function handle(): int
    {
        $basePath = trim($this->option('path') ?: 'barcodes', '/');
        $subject  = (string) $this->option('subject');
        $body     = (string) $this->option('body');
        $from     = $this->option('from');
        $fromName = $this->option('name');
        $dryRun   = (bool) $this->option('dry-run');
        $sleep = max(0, (int) $this->option('sleep')); // default 2

        // Distintos por (distrito, iglesia, email)
        $rows = Inscrito::query()
            ->select('distrito', 'iglesia', 'email')
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->distinct()
            ->orderBy('distrito')
            ->orderBy('iglesia')
            ->get();

        if ($rows->isEmpty()) {
            $this->warn('No hay registros con email.');
            return self::SUCCESS;
        }

        $this->info("Encontrados {$rows->count()} destinatarios únicos (distrito/iglesia/email).");

        $sent = $skippedNoZip = $skippedBadEmail = 0;

        foreach ($rows as $r) {
            $distritoSlug = Str::slug($r->distrito ?: 'sin-distrito');
            $iglesiaSlug  = Str::slug($r->iglesia  ?: 'sin-iglesia');

            $zipRel = "{$basePath}/{$distritoSlug}/{$iglesiaSlug}.zip";
            $zipAbs = Storage::path($zipRel);

            // Valida email
            $email = trim((string) $r->email);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->warn("Email inválido: {$email} ({$r->distrito} / {$r->iglesia})");
                $skippedBadEmail++;
                continue;
            }

            // Verifica que exista el zip de la iglesia
            if (!Storage::exists($zipRel)) {
                $this->warn("ZIP no encontrado: {$zipRel} ({$r->distrito} / {$r->iglesia})");
                $skippedNoZip++;
                continue;
            }

            $this->line(($dryRun ? '[DRY] ' : '') . "Enviando a {$email} → {$zipRel}");

            if ($dryRun) {
                continue;
            }

            try {
                Mail::to($email)->send(
                    (new IglesiasZipMail($r->distrito ?? '', $r->iglesia ?? '', $body, $zipAbs))
                        ->subject($subject)
                );

                if (!$dryRun && $sleep > 0) {
                    sleep($sleep);
                }

                $sent++;
            } catch (\Throwable $e) {
                $this->warn("Fallo enviando a {$email}: {$e->getMessage()}");
                continue;
            }

        }

        $this->newLine();
        $this->info("Envíos realizados: {$sent}");
        $this->line("Saltados (ZIP inexistente): {$skippedNoZip}");
        $this->line("Saltados (email inválido): {$skippedBadEmail}");

        if ($dryRun) {
            $this->comment('Dry-run: no se envió ningún correo.');
        }

        return self::SUCCESS;
    }
}
