<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 32);
            $table->string('vendor')->nullable();
            $table->date('acquired_at')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedTinyInteger('quality_score')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'type']);
        });

        Schema::create('column_mapping_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_source_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->json('mapping');
            $table->timestamps();
        });

        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_source_id')->nullable()->constrained()->nullOnDelete();
            $table->string('original_filename');
            $table->string('storage_path');
            $table->json('column_mapping')->nullable();
            $table->string('status', 32)->default('pending');
            $table->string('dns_status', 32)->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->json('stats')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'status']);
            $table->index('created_at');
        });

        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('email')->nullable();
            $table->string('email_hash', 64);
            $table->string('local_part')->nullable();
            $table->string('domain')->nullable()->index();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('company')->nullable();
            $table->string('title')->nullable();
            $table->string('country')->nullable();
            $table->string('phone')->nullable();
            $table->string('linkedin_url')->nullable();
            $table->json('custom_fields')->nullable();
            $table->foreignId('first_seen_batch_id')->nullable()->constrained('import_batches')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['workspace_id', 'email_hash']);
        });

        Schema::create('batch_leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('row_number');
            $table->json('raw')->nullable();
            $table->string('verdict', 32)->nullable();
            $table->unsignedTinyInteger('risk_score')->default(0);
            $table->json('reasons')->nullable();
            $table->string('suggested_fix')->nullable();
            $table->timestamps();
            $table->unique(['import_batch_id', 'row_number']);
            $table->index(['import_batch_id', 'verdict']);
        });

        Schema::create('domains_intel', function (Blueprint $table) {
            $table->string('domain')->primary();
            $table->json('mx_hosts')->nullable();
            $table->string('mx_provider')->nullable();
            $table->boolean('is_secure_gateway')->default(false);
            $table->string('dns_status', 32)->nullable();
            $table->boolean('has_spf')->nullable();
            $table->boolean('has_dmarc')->nullable();
            $table->boolean('is_catch_all')->nullable();
            $table->boolean('is_free_provider')->default(false);
            $table->boolean('is_disposable')->default(false);
            $table->timestamp('registered_at')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('suppressions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16);
            $table->string('value')->nullable();
            $table->string('value_hash', 64);
            $table->string('reason', 64);
            $table->string('source', 64);
            $table->string('bounce_detail', 64)->nullable();
            $table->boolean('permanent')->default(true);
            $table->timestamp('expires_at')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['workspace_id', 'type', 'value_hash']);
            $table->index(['workspace_id', 'expires_at']);
        });

        Schema::create('cleaning_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->boolean('enabled')->default(true);
            $table->json('params')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cleaning_rules');
        Schema::dropIfExists('suppressions');
        Schema::dropIfExists('domains_intel');
        Schema::dropIfExists('batch_leads');
        Schema::dropIfExists('leads');
        Schema::dropIfExists('import_batches');
        Schema::dropIfExists('column_mapping_templates');
        Schema::dropIfExists('lead_sources');
    }
};
