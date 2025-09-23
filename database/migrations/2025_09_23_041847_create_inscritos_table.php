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
            $table->string('distrito');
            $table->string('iglesia');
            $table->string('comida')->nullable();
            $table->dateTime('entrada')->nullable();
            $table->dateTime('salida')->nullable();
            $table->string('director')->nullable();
            $table->string('telefono')->nullable();
            $table->string('email')->nullable();
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
