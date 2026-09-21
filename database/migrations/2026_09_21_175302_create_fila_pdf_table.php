<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tabela relatorios
        Schema::create('relatorios', function (Blueprint $table) {
            $table->id(); // Equivalente ao INT PRIMARY KEY AUTO_INCREMENT
            $table->dateTime('create_time')->nullable();
            $table->string('titulo', 255)->nullable();
            $table->text('descricao')->nullable();
            $table->dateTime('data_expiracao')->nullable();
            $table->string('hash_arquivo', 64)->unique()->nullable();
        });

        // Tabela fila_pdf
        Schema::create('fila_pdf', function (Blueprint $table) {
            $table->id();
            $table->string('nome_arquivo', 255);
            $table->string('caminho_temp', 255);
            $table->enum('status', ['pendente', 'processando', 'concluido', 'erro'])->default('pendente');
            $table->text('mensagem_erro')->nullable();
            $table->timestamp('criado_em')->useCurrent(); // Mantém o nome exato do seu SQL
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fila_pdf');
        Schema::dropIfExists('relatorios');
    }
};