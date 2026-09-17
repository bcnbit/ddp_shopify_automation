<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * Base para pruebas que necesitan el contenedor y la configuración de Laravel
 * pero no tocan la base de datos (sanitizadores, reglas de validación, etc.).
 */
abstract class UnitTestCase extends BaseTestCase
{
    //
}
