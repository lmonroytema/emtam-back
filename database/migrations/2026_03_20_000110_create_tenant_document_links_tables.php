<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenant_document_links')) {
            Schema::create('tenant_document_links', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('tenant_id', 255);
                $table->unsignedBigInteger('folder_id');
                $table->string('title', 255);
                $table->string('url', 2000);
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->timestamps();

                $table->index(['tenant_id']);
                $table->index(['folder_id']);
                $table->foreign('folder_id')->references('id')->on('tenant_document_folders')->onDelete('cascade');
            });
        }

        if (! Schema::hasTable('tenant_document_link_riesgo_trs')) {
            Schema::create('tenant_document_link_riesgo_trs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('tenant_id', 255);
                $table->unsignedBigInteger('link_id');
                $table->string('riesgo_id', 255);
                $table->timestamps();

                $table->unique(['tenant_id', 'link_id', 'riesgo_id'], 'uq_tenant_link_riesgo');
                $table->index(['tenant_id', 'riesgo_id']);
                $table->foreign('link_id')->references('id')->on('tenant_document_links')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_document_link_riesgo_trs');
        Schema::dropIfExists('tenant_document_links');
    }
};
