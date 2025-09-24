<?php

namespace App\Console\Commands;

use App\Models\Inscrito;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use Picqer\Barcode\BarcodeGeneratorPNG;
use ZipArchive;

class InscritosGenerateBarcodes extends Command
{
    protected $signature = 'inscritos:barcodes
        {--ids= : Coma-separado de IDs específicos (ej: 1,2,3)}
        {--path=barcodes : Carpeta base dentro de storage/app para guardar las imágenes}';

    protected $description = 'Genera PNG con código de barras (Code128) para inscritos (id como barcode, nombre arriba) y guarda en /<path>/<distrito>/<iglesia>/. Al final genera un ZIP por carpeta.';

    public function handle(): int
    {
        if (!class_exists(ZipArchive::class)) {
            $this->error('ZipArchive no está disponible en esta instalación de PHP.');
            return self::FAILURE;
        }

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
                    // Carpetas por distrito/iglesia
                    $distrito = Str::slug($inscrito->distrito ?: 'sin-distrito');
                    $iglesia  = Str::slug($inscrito->iglesia  ?: 'sin-iglesia');
                    $dir = "{$basePath}/{$distrito}/{$iglesia}";
                    Storage::makeDirectory($dir);

                    // Barcode PNG (Code128)
                    $barcodePng = $generator->getBarcode((string) $inscrito->id, $generator::TYPE_CODE_128, 2, 80);

                    // Lienzo BLANCO
                    $img = $manager->create($width, $height)->fill('#ffffff');

                    // Nombre arriba (negro)
                    $name = (string) $inscrito->nombre;
                    $img->text(
                        $name,
                        intval($width / 2),
                        80,
                        function ($font) use ($hasFont, $fontPath) {
                            if ($hasFont) $font->filename($fontPath);
                            $font->size(30);
                            $font->color('#000000');
                            $font->align('center');
                            $font->valign('middle');
                        }
                    );

                    // Insertar barcode centrado
                    $barcodeImage = $manager->read($barcodePng);
                    $maxBarcodeWidth = intval($width * 0.85);
                    if ($barcodeImage->width() > $maxBarcodeWidth) {
                        $barcodeImage->scaleDown($maxBarcodeWidth, null);
                    }
                    $img->place($barcodeImage, 'center', 0, 30);

                    // ID bajo el barcode
                    $img->text(
                        'ID: ' . $inscrito->id,
                        intval($width / 2),
                        $height - 30,
                        function ($font) use ($hasFont, $fontPath) {
                            if ($hasFont) $font->filename($fontPath);
                            $font->size(28);
                            $font->color('#000000');
                            $font->align('center');
                            $font->valign('bottom');
                        }
                    );

                    // Guardar PNG
                    $filename = $inscrito->id . '-' . Str::slug($inscrito->nombre ?? 'inscrito') . '.png';
                    $fullPath = "{$dir}/{$filename}";
                    Storage::put($fullPath, $img->toPng()->toString());
                } catch (\Throwable $e) {
                    $this->warn("\nError en ID {$inscrito->id}: " . $e->getMessage());
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("Imágenes listas en storage/app/{$basePath}/<distrito>/<iglesia>/");

        // ===== ZIPS POR CARPETA =====
        $this->info('Creando archivos ZIP por carpeta…');
        $this->zipPerFolder($basePath);

        $this->newLine();
        $this->info('Proceso completado.');
        $this->line("Iglesias: storage/app/{$basePath}/<distrito>/<iglesia>.zip");
        $this->line("Distritos: storage/app/{$basePath}/<distrito>.zip");

        return self::SUCCESS;
    }

    /**
     * Crea ZIPs para cada carpeta:
     * - Un ZIP por cada carpeta de IGLESIA (con sus PNGs).
     * - Un ZIP por cada carpeta de DISTRITO (contiene todas las subcarpetas de iglesias y/o PNGs).
     */
    protected function zipPerFolder(string $basePath): void
    {
        // 1) Por IGLESIA
        foreach (Storage::directories($basePath) as $districtDir) {
            foreach (Storage::directories($districtDir) as $iglesiaDir) {
                $zipPath = $iglesiaDir . '.zip';
                $this->createZipFromStorageFolder($iglesiaDir, $zipPath, /*preserveSubfolders*/ false);
                $this->line("ZIP iglesia: " . $zipPath);
            }
        }

        // 2) Por DISTRITO (incluye subcarpetas de iglesias)
        foreach (Storage::directories($basePath) as $districtDir) {
            $zipPath = $districtDir . '.zip';
            $this->createZipFromStorageFolder($districtDir, $zipPath, /*preserveSubfolders*/ true);
            $this->line("ZIP distrito: " . $zipPath);
        }
    }

    /**
     * Crea un ZIP a partir de una carpeta manejada por Storage (disk local).
     *
     * @param string $folderRelative  Ruta relativa en Storage (p.ej. "barcodes/tijuana/iglesia-x")
     * @param string $zipRelative     Ruta relativa donde guardar el ZIP (p.ej. "barcodes/tijuana/iglesia-x.zip")
     * @param bool   $preserveSubfolders  true = conserva estructura interna; false = mete solo archivos planos de ese nivel.
     */
    protected function createZipFromStorageFolder(string $folderRelative, string $zipRelative, bool $preserveSubfolders = true): void
    {
        // Asegura directorio destino
        Storage::makeDirectory(dirname($zipRelative));

        $zipAbsPath = Storage::path($zipRelative);
        // Si existe, elimínalo para recrearlo limpio
        if (file_exists($zipAbsPath)) {
            @unlink($zipAbsPath);
        }

        $zip = new ZipArchive();
        if ($zip->open($zipAbsPath, ZipArchive::CREATE) !== TRUE) {
            $this->warn("No se pudo crear ZIP: {$zipRelative}");
            return;
        }

        if ($preserveSubfolders) {
            // incluye todo el árbol (archivos y subcarpetas)
            $allFiles = Storage::allFiles($folderRelative);
            foreach ($allFiles as $file) {
                $localPath = Storage::path($file);
                // nombre relativo dentro del zip (sin el prefijo de la carpeta base)
                $relativeName = ltrim(substr($file, strlen($folderRelative)), '/\\');
                $zip->addFile($localPath, $relativeName);
            }
        } else {
            // Solo archivos directos del nivel (sin recursión)
            $files = Storage::files($folderRelative);
            foreach ($files as $file) {
                $localPath = Storage::path($file);
                $basename  = basename($file);
                $zip->addFile($localPath, $basename);
            }
        }

        $zip->close();
    }
}
