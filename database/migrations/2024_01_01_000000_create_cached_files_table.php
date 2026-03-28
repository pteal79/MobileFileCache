<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cached_files', function (Blueprint $table) {
            $table->id();

            // The original remote URL as submitted by the caller
            $table->text('original_url');

            // SHA-256 hash of the original URL, used for fast lookups and deduplication
            $table->string('url_hash', 64)->unique();

            // Storage configuration at time of caching
            $table->string('disk', 100)->default('mobile_public');
            $table->string('folder', 255)->default('cached_files');

            // Relative path within the disk, e.g. "cached_files/abc123.jpg"
            $table->string('local_path', 500);

            // The generated safe filename, e.g. "abc123def456.jpg"
            $table->string('filename', 255);

            // File metadata
            $table->string('mime_type', 100)->nullable();
            $table->string('extension', 20)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();

            // Lifecycle status
            // pending  = job dispatched but download not yet complete
            // cached   = file is downloaded and available locally
            // failed   = download or validation failed
            $table->enum('status', ['pending', 'cached', 'failed'])->default('pending')->index();

            // Timestamps
            $table->timestamp('last_accessed_at')->nullable()->index();
            $table->timestamp('cached_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->text('error_message')->nullable();

            $table->timestamps();

            // Index for age-based queries (purge old files)
            $table->index(['status', 'last_accessed_at']);
            $table->index(['status', 'cached_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cached_files');
    }
};
