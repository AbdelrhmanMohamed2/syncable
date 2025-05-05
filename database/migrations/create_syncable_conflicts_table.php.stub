<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('syncable_conflicts', function (Blueprint $table) {
            $table->id();
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->json('conflicts')->nullable();
            $table->json('local_values')->nullable();
            $table->json('remote_values')->nullable();
            $table->string('origin_system_id');
            $table->string('status')->default('pending');
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolved_by')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->string('tenant_id')->nullable();
            $table->timestamps();
            
            $table->index(['model_type', 'model_id']);
            $table->index('status');
            $table->index('tenant_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('syncable_conflicts');
    }
}; 