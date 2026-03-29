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
        if (Schema::hasTable('operacoes')) {
            try {
                Schema::table('operacoes', function (Blueprint $table): void {
                    $table->index(['status', 'id'], 'operacoes_status_id_index');
                });
            } catch (\Throwable) {

            }
        }

        if (Schema::hasTable('clientes')) {
            try {
                Schema::table('clientes', function (Blueprint $table): void {
                    $table->index('nome', 'clientes_nome_index');
                });
            } catch (\Throwable) {

            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('operacoes')) {
            try {
                Schema::table('operacoes', function (Blueprint $table): void {
                    $table->dropIndex('operacoes_status_id_index');
                });
            } catch (\Throwable) {
                // índice inexistente
            }
        }

        if (Schema::hasTable('clientes')) {
            try {
                Schema::table('clientes', function (Blueprint $table): void {
                    $table->dropIndex('clientes_nome_index');
                });
            } catch (\Throwable) {
                // índice inexistente
            }
        }
    }
};
