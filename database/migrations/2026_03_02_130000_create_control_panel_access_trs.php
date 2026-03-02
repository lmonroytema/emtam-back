<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('control_panel_access_trs')) {
            return;
        }

        Schema::create('control_panel_access_trs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('tenant_id', 191);
            $table->string('activation_id', 191);
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'activation_id']);
            $table->index(['tenant_id', 'user_id']);
            $table->unique(['tenant_id', 'activation_id', 'user_id'], 'control_panel_access_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('control_panel_access_trs');
    }
};
