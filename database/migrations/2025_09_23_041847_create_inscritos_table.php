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
        Schema::create('inscritos', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->string('nombre');
            $table->string('edad')->nullable();
            $table->string('distrito')->nullable();
            $table->string('iglesia')->nullable();
            $table->string('asiste_como')->nullable();
            $table->string('bautizado')->nullable();
            $table->string('director')->nullable();
            $table->string('telefono')->nullable();
            $table->string('email')->nullable();
            $table->string('tipo_sangre')->nullable();
            $table->text('enfermedad')->nullable();
            $table->text('medicamento')->nullable();
            $table->text('alergia')->nullable();
            $table->string('precio')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inscritos');
    }
};
