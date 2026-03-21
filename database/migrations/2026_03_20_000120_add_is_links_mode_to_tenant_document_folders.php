<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tenant_document_folders')) {
            return;
        }
        Schema::table('tenant_document_folders', function (Blueprint $table) {
            if (! Schema::hasColumn('tenant_document_folders', 'is_links_mode')) {
                $table->boolean('is_links_mode')->default(false)->after('name');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('tenant_document_folders')) {
            return;
        }
        Schema::table('tenant_document_folders', function (Blueprint $table) {
            if (Schema::hasColumn('tenant_document_folders', 'is_links_mode')) {
                $table->dropColumn('is_links_mode');
            }
        });
    }
};
