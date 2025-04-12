<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('messages')) {
            Schema::create('messages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->onDelete('cascade');
                $table->foreignId('contact_id')->constrained()->onDelete('cascade');
                $table->string('meta_message_id')->nullable();
                $table->enum('direction', ['in', 'out']);
                $table->text('content');
                $table->string('status')->default('sending');
                $table->string('message_type')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamps();

                $table->index(['contact_id', 'created_at']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
