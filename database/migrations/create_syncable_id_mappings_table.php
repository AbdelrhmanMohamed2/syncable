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
        Schema::create('syncable_id_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('local_model_type', 100);
            $table->unsignedBigInteger('local_model_id');
            $table->string('remote_model_type', 100);
            $table->unsignedBigInteger('remote_model_id');
            $table->string('system_id', 50);
            $table->string('tenant_id', 50)->nullable();
            $table->timestamps();

            $table->index(['local_model_type', 'local_model_id']);
            $table->index(['remote_model_type', 'remote_model_id']);
            $table->index(['system_id']);
            $table->index(['tenant_id']);

            $table->unique(['local_model_type', 'local_model_id', 'system_id'], 'sync_map_local_unique');
            $table->unique(['remote_model_type', 'remote_model_id', 'system_id'], 'sync_map_remote_unique');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('syncable_id_mappings');
    }
}; 