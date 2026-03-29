<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('mobile_file_cache', function (Blueprint $table): void {
            $table->id();
            $table->text('remote_url');
            $table->string('remote_url_hash', 64)->unique();
            $table->string('disk')->default('mobile_public');
            $table->string('path')->unique();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_file_cache');
    }
};
