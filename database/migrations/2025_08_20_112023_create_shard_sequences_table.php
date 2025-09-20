<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shard_sequences', function (Blueprint $table): void {
            $table->string('table')->primary();
            $table->unsignedBigInteger('last_id')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shard_sequences');
    }
};
