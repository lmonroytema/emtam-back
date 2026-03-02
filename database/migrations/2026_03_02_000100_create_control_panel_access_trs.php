<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('control_panel_access_trs')) {
            Schema::create('control_panel_access_trs', function (Blueprint $table) {
                $table->id();
                $table->string('tenant_id', 191);
                $table->string('activation_id', 191);
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
                $table->unique(['tenant_id', 'activation_id', 'user_id'], 'control_panel_access_unique');
                $table->index(['tenant_id', 'activation_id'], 'control_panel_access_activation_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('control_panel_access_trs')) {
            Schema::drop('control_panel_access_trs');
        }
    }
};
