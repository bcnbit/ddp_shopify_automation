<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Instalación OAuth de la aplicación en Shopify (RFC-0009).
 *
 * Sustituye al token estático en el entorno de RFC-0004. La credencial que
 * produce el flujo OAuth pertenece a una instalación concreta, así que se guarda
 * con ella en lugar de en una variable de entorno: así se puede rotar sin
 * desplegar, se puede auditar y no acaba copiada a un `.env.example`.
 *
 * `access_token` se cifra con el cast `encrypted` del modelo (APP_KEY), no aquí:
 * el valor sólo debe existir en claro en memoria. El tipo es `text` porque el
 * criptograma de un token supera la longitud de una columna `string` corta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopify_installations', function (Blueprint $table) {
            $table->id();

            // Lo confirma Shopify (`shop.myshopifyDomain`), no lo teclea nadie.
            $table->string('shop_domain', 255)->unique();

            // Offline access token (`shpat_…`) cifrado con APP_KEY.
            $table->text('access_token');

            // Handles concedidos, tal y como los devolvió el intercambio.
            $table->json('scopes')->nullable();

            // Un token expirable trae `refresh_token` y `expires_in`. Se guardan
            // las marcas para poder avisar de que hace falta el refresco, que
            // esta fase no implementa (ver RFC-0009 §4.3).
            $table->boolean('is_expiring')->default(false);
            $table->timestamp('expires_at')->nullable();

            $table->timestamp('installed_at');
            $table->timestamp('last_checked_at')->nullable();
            $table->string('last_check_error', 255)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_installations');
    }
};
