<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenant_document_folders')) {
            Schema::create('tenant_document_folders', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('tenant_id', 255);
                $table->string('name', 255);
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->timestamps();

                $table->index(['tenant_id']);
            });
        }

        if (! Schema::hasTable('tenant_documents')) {
            Schema::create('tenant_documents', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('tenant_id', 255);
                $table->unsignedBigInteger('folder_id');
                $table->string('name', 255);
                $table->string('original_name', 500);
                $table->string('stored_name', 500);
                $table->string('path', 1000);
                $table->unsignedBigInteger('size_bytes')->default(0);
                $table->string('mime_type', 255)->nullable();
                $table->string('extension', 50)->nullable();
                $table->unsignedBigInteger('uploaded_by_user_id')->nullable();
                $table->timestamps();

                $table->index(['tenant_id']);
                $table->index(['folder_id']);
                $table->foreign('folder_id')->references('id')->on('tenant_document_folders')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_documents');
        Schema::dropIfExists('tenant_document_folders');
    }
};
