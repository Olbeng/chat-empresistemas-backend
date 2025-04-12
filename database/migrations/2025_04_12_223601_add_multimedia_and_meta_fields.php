<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Añadir campos a la tabla users
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'token_meta')) {
                $table->text('token_meta')->nullable();
            }
            if (!Schema::hasColumn('users', 'verify_token')) {
                $table->text('verify_token')->nullable();
            }
            if (!Schema::hasColumn('users', 'server_origin')) {
                $table->text('server_origin')->nullable();
            }
        });

        // Añadir campos multimedia a la tabla messages
        Schema::table('messages', function (Blueprint $table) {
            if (!Schema::hasColumn('messages', 'media_url')) {
                $table->string('media_url')->nullable()->after('message_type');
            }
            if (!Schema::hasColumn('messages', 'media_path')) {
                $table->string('media_path')->nullable()->after('media_url');
            }
            if (!Schema::hasColumn('messages', 'caption')) {
                $table->text('caption')->nullable()->after('media_path');
            }
            if (!Schema::hasColumn('messages', 'media_metadata')) {
                $table->json('media_metadata')->nullable()->after('caption');
            }
            if (!Schema::hasColumn('messages', 'error_message')) {
                $table->string('error_message')->nullable()->after('status');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Eliminar campos de la tabla users
        Schema::table('users', function (Blueprint $table) {
            $userColumns = ['token_meta', 'verify_token', 'server_origin'];

            foreach ($userColumns as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        // Eliminar campos de la tabla messages
        Schema::table('messages', function (Blueprint $table) {
            $messageColumns = ['media_url', 'media_path', 'caption', 'media_metadata', 'error_message'];

            foreach ($messageColumns as $column) {
                if (Schema::hasColumn('messages', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
