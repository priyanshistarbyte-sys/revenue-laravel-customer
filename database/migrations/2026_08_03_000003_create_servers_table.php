<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('servers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('ssh_host');
            $table->integer('ssh_port')->default(22);
            $table->string('ssh_username');
            $table->text('ssh_password');                 // encrypted at rest
            $table->string('bt_panel_url');
            $table->text('bt_panel_key');                 // encrypted at rest
            $table->string('bt_panel_php_version')->default('74');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('servers');
    }
};
