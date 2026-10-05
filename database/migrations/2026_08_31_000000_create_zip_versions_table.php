<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Archived older versions of a zip. When a zip's file is replaced and the
     * user chooses "save older version", the current file is stored here (with
     * a user-given label) instead of being deleted.
     */
    public function up(): void
    {
        Schema::create('zip_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('zip_id');
            $table->string('name');                       // version label given by the user
            $table->string('file');                       // stored path on the public disk
            $table->string('original_name')->nullable();  // filename to use when downloading
            $table->timestamps();

            $table->index('zip_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zip_versions');
    }
};
