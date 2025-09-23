<?php

namespace App\Console\Commands;

use App\Models\Inscrito;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use Picqer\Barcode\BarcodeGeneratorPNG;

class InscritosGenerateBarcodes extends Command
{
    protected $signature = 'inscritos:barcodes
        {--ids= : Coma-separado de IDs específicos (ej: 1,2,3)}
        {--path=barcodes : Carpeta base dentro de storage/app para guardar las imágenes}';

    protected $description = 'Genera PNG con código de barras (Code128) para inscritos (id como barcode, nombre arriba) y guarda en /<path>/<distrito>/<iglesia>/';

    public function handle(): int
    {
        $basePath = trim($this->option('path') ?: 'barcodes', '/');
        Storage::makeDirectory($basePath);

        $query = Inscrito::query()->select(['id', 'nombre', 'distrito', 'iglesia']);

        if ($this->option('ids')) {
            $ids = collect(explode(',', $this->option('ids')))
                ->map(fn($v) => (int) trim($v))
                ->filter();
            if ($ids->isEmpty()) {
                $this->error('La opción --ids no tiene IDs válidos.');
                return self::INVALID;
            }
            $query->whereIn('id', $ids->all());
        }

        $total = $query->count();
        if ($total === 0) {
            $this->warn('No hay inscritos que procesar.');
            return self::SUCCESS;
        }

        $this->info("Generando códigos de barras para {$total} inscritos…");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $manager = new ImageManager(new Driver());
        $generator = new BarcodeGeneratorPNG();

        $fontPath = resource_path('fonts/Inter_28pt-Regular.ttf'); // opcional
        $hasFont = file_exists($fontPath);

        // Tamaño de la imagen
        $width  = 600;
        $height = 300;

        $query->orderBy('id')->chunk(200, function ($chunk) use (
            $manager, $generator, $basePath, $fontPath, $hasFont, $width, $height, $bar
        ) {
            foreach ($chunk as $inscrito) {
                try {
                    // Normalizar carpetas por distrito/iglesia
                    $distrito = Str::slug($inscrito->distrito ?: 'sin-distrito');
                    $iglesia  = Str::slug($inscrito->iglesia  ?: 'sin-iglesia');
                    $dir = "{$basePath}/{$distrito}/{$iglesia}";
                    Storage::makeDirectory($dir);

                    // 1) Barcode PNG (Code128)
                    $barcodePng = $generator->getBarcode((string) $inscrito->id, $generator::TYPE_CODE_128, 2, 120);

                    // 2) Lienzo con fondo BLANCO
                    $img = $manager->create($width, $height)->fill('#ffffff');

                    // 3) Nombre (arriba, negro)
                    $name = (string) $inscrito->nombre;
                    $img->text(
                        $name,
                        intval($width / 2),
                        80,
                        function ($font) use ($hasFont, $fontPath) {
                            if ($hasFont) {
                                $font->filename($fontPath);
                            }
                            $font->size(34);
                            $font->color('#000000');
                            $font->align('center');
                            $font->valign('middle');
                        }
                    );

                    // 4) Insertar barcode centrado
                    $barcodeImage = $manager->read($barcodePng); // normaliza a PNG
                    $maxBarcodeWidth = intval($width * 0.85);
                    if ($barcodeImage->width() > $maxBarcodeWidth) {
                        $barcodeImage->scaleDown($maxBarcodeWidth, null);
                    }
                    // ligeramente abajo del centro para dejar espacio al ID
                    $img->place($barcodeImage, 'center', 0, 30);

                    // 5) Escribir el ID bajo el barcode
                    $img->text(
                        'ID: ' . $inscrito->id,
                        intval($width / 2),
                        $height - 30,
                        function ($font) use ($hasFont, $fontPath) {
                            if ($hasFont) {
                                $font->filename($fontPath);
                            }
                            $font->size(28);
                            $font->color('#000000');
                            $font->align('center');
                            $font->valign('bottom');
                        }
                    );

                    // 6) Guardar en /<path>/<distrito>/<iglesia>/{id-nombre}.png
                    $filename = $inscrito->id . '-' . Str::slug($inscrito->nombre ?? 'inscrito') . '.png';
                    $fullPath = "{$dir}/{$filename}";

                    // Forzar PNG (evita: No encoder found for media type application/octet-stream)
                    Storage::put($fullPath, $img->toPng()->toString());
                } catch (\Throwable $e) {
                    $this->warn("\nError en ID {$inscrito->id}: " . $e->getMessage());
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("Listo. Revisa: storage/app/{$basePath}/<distrito>/<iglesia>/");
        $this->line("Si tienes el symlink: /storage/{$basePath}/<distrito>/<iglesia>/ en el navegador.");

        return self::SUCCESS;
    }
}
