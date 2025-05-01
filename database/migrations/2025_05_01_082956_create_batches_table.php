<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('batches', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade'); // Admin/Instructor
            $table->timestamps();
        });

        Schema::create('batch_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // Only students
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('batch_user');
        Schema::dropIfExists('batches');
    }
};
