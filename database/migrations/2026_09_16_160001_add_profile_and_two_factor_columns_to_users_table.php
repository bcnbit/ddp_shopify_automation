<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Perfil de usuario y segundo factor (RFC-0001).
     *
     * `app_authentication_*` son los campos que espera Filament 4 para el TOTP.
     * Se guardan cifrados a nivel de aplicación mediante el cast del modelo,
     * por lo que APP_KEY debe conservarse: rotarla invalida el 2FA existente.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('job_title')->nullable()->after('email');
            $table->char('locale', 5)->default('es')->after('job_title');
            $table->boolean('is_active')->default(true)->after('locale');
            $table->timestamp('last_login_at')->nullable()->after('is_active');
            $table->text('app_authentication_secret')->nullable();
            $table->text('app_authentication_recovery_codes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'job_title',
                'locale',
                'is_active',
                'last_login_at',
                'app_authentication_secret',
                'app_authentication_recovery_codes',
            ]);
        });
    }
};
