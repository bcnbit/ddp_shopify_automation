<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Support\Media\MediaRules;
use Illuminate\Http\UploadedFile;
use Tests\UnitTestCase;

class MediaValidationRulesTest extends UnitTestCase
{
    public function test_permite_los_formatos_del_rfc_0005(): void
    {
        $this->assertSame(['jpg', 'jpeg', 'png', 'webp'], MediaRules::allowedExtensions());
        $this->assertContains('image/jpeg', MediaRules::allowedMimeTypes());
        $this->assertContains('image/png', MediaRules::allowedMimeTypes());
        $this->assertContains('image/webp', MediaRules::allowedMimeTypes());
    }

    public function test_rechaza_svg_en_el_mvp(): void
    {
        $this->assertNotContains('svg', MediaRules::allowedExtensions());
        $this->assertNotContains('image/svg+xml', MediaRules::allowedMimeTypes());
    }

    public function test_aplica_el_limite_de_20_mb_configurado(): void
    {
        $this->assertSame(20480, MediaRules::maxKilobytes());
    }

    public function test_exige_una_resolucion_minima(): void
    {
        $this->assertSame(800, MediaRules::minWidth());
        $this->assertSame(800, MediaRules::minHeight());
    }

    public function test_genera_reglas_de_validacion_ejecutables(): void
    {
        $rules = MediaRules::fileValidationRules();

        $this->assertContains('required', $rules);
        $this->assertContains('file', $rules);

        $hasMimetypeRule = false;

        foreach ($rules as $rule) {
            if (is_string($rule) && str_starts_with($rule, 'mimetypes:')) {
                $hasMimetypeRule = true;
            }
        }

        $this->assertTrue($hasMimetypeRule, 'Debe validarse el MIME real del archivo.');
    }

    public function test_rechaza_un_archivo_svg_real(): void
    {
        $svg = UploadedFile::fake()->createWithContent(
            'logo.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><rect width="10" height="10"/></svg>',
        );

        $validator = validator(
            ['file' => $svg],
            ['file' => MediaRules::fileValidationRules()],
        );

        $this->assertTrue($validator->fails());
    }

    public function test_rechaza_un_archivo_que_se_hace_pasar_por_imagen(): void
    {
        // Extensión .jpg pero contenido de texto. Se usa un archivo real en disco
        // porque los dobles de Laravel informan el MIME a partir del nombre y no
        // permitirían comprobar la detección por contenido.
        $validator = validator(
            ['file' => $this->realFile('camiseta.jpg', 'esto no es una imagen')],
            ['file' => MediaRules::fileValidationRules()],
        );

        $this->assertTrue($validator->fails(), 'El MIME debe detectarse por contenido, no por extensión.');
    }

    public function test_rechaza_una_imagen_por_encima_del_limite_de_tamano(): void
    {
        $tooBig = UploadedFile::fake()->image('grande.jpg', 1200, 1200)
            ->size(MediaRules::maxKilobytes() + 1);

        $validator = validator(
            ['file' => $tooBig],
            ['file' => MediaRules::fileValidationRules()],
        );

        $this->assertTrue($validator->fails());
    }

    public function test_acepta_una_imagen_jpeg_valida(): void
    {
        $image = UploadedFile::fake()->image('camiseta.jpg', 1200, 1200);

        $validator = validator(
            ['file' => $image],
            ['file' => MediaRules::fileValidationRules()],
        );

        $this->assertFalse($validator->fails(), (string) $validator->errors());
    }

    public function test_acepta_una_imagen_png_valida(): void
    {
        $image = UploadedFile::fake()->image('camiseta.png', 1200, 1200);

        $validator = validator(
            ['file' => $image],
            ['file' => MediaRules::fileValidationRules()],
        );

        $this->assertFalse($validator->fails(), (string) $validator->errors());
    }

    /**
     * Crea un archivo real en disco para que `getMimeType()` inspeccione su
     * contenido en lugar de fiarse del nombre.
     */
    private function realFile(string $name, string $content): \Symfony\Component\HttpFoundation\File\UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'media-rules-');
        file_put_contents($path, $content);

        return new \Symfony\Component\HttpFoundation\File\UploadedFile($path, $name, null, null, true);
    }
}
