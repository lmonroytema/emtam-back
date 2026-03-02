<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('audit_log_trs')) {
            Schema::create('audit_log_trs', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('tenant_id', 191);
                $table->string('plan_id', 191)->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('event_type', 191);
                $table->string('module', 191)->nullable();
                $table->string('entity_id', 191)->nullable();
                $table->string('entity_type', 191)->nullable();
                $table->json('previous_value')->nullable();
                $table->json('new_value')->nullable();
                $table->text('justification')->nullable();
                $table->string('ip_origin', 191)->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['tenant_id', 'plan_id'], 'audit_log_plan_idx');
                $table->index(['tenant_id', 'event_type'], 'audit_log_event_idx');
                $table->index(['tenant_id', 'user_id'], 'audit_log_user_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('audit_log_trs')) {
            Schema::drop('audit_log_trs');
        }
    }
};
