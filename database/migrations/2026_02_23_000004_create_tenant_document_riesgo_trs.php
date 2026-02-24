<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tenant_document_riesgo_trs')) {
            return;
        }

        Schema::create('tenant_document_riesgo_trs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('tenant_id', 255);
            $table->unsignedBigInteger('document_id');
            $table->string('riesgo_id', 255);
            $table->timestamps();

            $table->index(['tenant_id']);
            $table->index(['document_id']);
            $table->index(['riesgo_id']);
            $table->unique(['tenant_id', 'document_id', 'riesgo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_document_riesgo_trs');
    }
};
