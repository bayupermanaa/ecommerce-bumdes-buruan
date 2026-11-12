<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('umkm_store_id')->constrained('umkm_stores')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['customer_id', 'umkm_store_id']);
            $table->index(['umkm_store_id', 'updated_at']);
        });

        Schema::create('chat_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('chat_conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->restrictOnDelete();
            $table->text('body')->nullable();
            $table->string('image_path')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['chat_conversation_id', 'created_at']);
            $table->index(['sender_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_conversations');
    }
};
