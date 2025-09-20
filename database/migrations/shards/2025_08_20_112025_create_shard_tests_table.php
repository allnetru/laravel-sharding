<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shard_tests', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('value');
            $table->boolean('is_replica')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shard_tests');
    }
};
