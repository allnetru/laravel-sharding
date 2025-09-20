<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shard_slots', function (Blueprint $table) {
            $table->id();
            $table->string('table');
            $table->unsignedBigInteger('slot');
            $table->string('connection');
            $table->json('replicas')->nullable();
            $table->timestamps();

            $table->unique(['table', 'slot']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shard_slots');
    }
};
