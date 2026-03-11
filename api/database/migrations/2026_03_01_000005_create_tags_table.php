<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name')->unique();
            $table->string('color', 7)->default('#6B7280');
            $table->timestamps();
        });

        Schema::create('time_entry_tag', function (Blueprint $table) {
            $table->foreignUuid('time_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['time_entry_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_entry_tag');
        Schema::dropIfExists('tags');
    }
};
