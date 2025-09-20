<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shard_ranges', function (Blueprint $table) {
            $table->id();
            $table->string('table');
            $table->unsignedBigInteger('start');
            $table->unsignedBigInteger('end');
            $table->string('connection');
            $table->json('replicas')->nullable();
            $table->timestamps();

            $table->index(['table', 'start', 'end']);
            $table->unique(['table', 'start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shard_ranges');
    }
};
