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
        Schema::create('runs', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('type')->nullable();
            $table->nullableMorphs('subject');
            $table->nullableMorphs('owner');
            $table->unsignedBigInteger('total_items')->nullable();
            $table->unsignedBigInteger('processed_items')->default(0);
            $table->unsignedBigInteger('failed_items')->default(0);
            $table->json('metadata')->nullable();
            $table->longText('exception')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['completed_at', 'failed_at', 'cancelled_at', 'created_at'], 'runs_active_index');
            $table->index(['failed_at', 'created_at']);
            $table->index(['cancelled_at', 'created_at']);
            $table->index(['type', 'created_at']);
            $table->index('created_at');
        });

        Schema::create('run_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained()->cascadeOnDelete();
            $table->nullableMorphs('resource');
            $table->string('status');
            $table->json('metadata')->nullable();
            $table->longText('exception')->nullable();
            $table->timestamp('created_at');

            $table->index(['run_id', 'status', 'created_at']);
            $table->index(['run_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('run_items');
        Schema::dropIfExists('runs');
    }
};
