<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('logic-map.storage.connection');
    }

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        $schema->create('lm_snapshots', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->unsignedInteger('schema_version');
            $table->string('analysis_version', 64);
            $table->string('indexed_at', 64);
            $table->string('source_fingerprint', 64);
            $table->text('phase_metrics');
        });

        $schema->create('lm_active_snapshot', function (Blueprint $table): void {
            $table->unsignedTinyInteger('singleton')->primary();
            $table->string('snapshot_id', 64);
        });

        $schema->create('lm_files', function (Blueprint $table): void {
            $table->id();
            $table->string('snapshot_id', 64);
            $table->string('path', 500);
            $table->string('content_hash', 64);
            $table->unsignedBigInteger('size');
            $table->unique(['snapshot_id', 'path'], 'lm_files_snapshot_path_unique');
            $table->index(['snapshot_id', 'content_hash'], 'lm_files_snapshot_content_hash');
        });

        $schema->create('lm_nodes', function (Blueprint $table): void {
            $table->string('snapshot_id', 64);
            $table->string('node_id', 500);
            $table->string('kind', 40);
            $table->string('name', 255);
            $table->string('qualified_name', 500)->nullable();
            $table->string('file', 500)->nullable();
            $table->unsignedInteger('start_line')->nullable();
            $table->unsignedInteger('end_line')->nullable();
            $table->text('attributes');
            $table->primary(['snapshot_id', 'node_id']);
            $table->index(['snapshot_id', 'kind'], 'lm_nodes_snapshot_kind');
            $table->index(['snapshot_id', 'qualified_name'], 'lm_nodes_snapshot_qualified_name');
            $table->index(['snapshot_id', 'file', 'start_line', 'end_line'], 'lm_nodes_snapshot_file_span');
        });

        $schema->create('lm_edges', function (Blueprint $table): void {
            $table->string('snapshot_id', 64);
            $table->string('edge_id', 64);
            $table->string('source_id', 500);
            $table->string('target_id', 500);
            $table->string('type', 40);
            $table->text('site_key');
            $table->primary(['snapshot_id', 'edge_id']);
            $table->index(['snapshot_id', 'source_id', 'type'], 'lm_edges_snapshot_source_type');
            $table->index(['snapshot_id', 'target_id', 'type'], 'lm_edges_snapshot_target_type');
        });

        $schema->create('lm_evidence', function (Blueprint $table): void {
            $table->string('snapshot_id', 64);
            $table->string('evidence_id', 64);
            $table->string('origin', 20);
            $table->string('detector', 100);
            $table->string('certainty', 20);
            $table->string('file', 500)->nullable();
            $table->unsignedInteger('start_line')->nullable();
            $table->unsignedInteger('end_line')->nullable();
            $table->text('expression')->nullable();
            $table->text('condition_text')->nullable();
            $table->text('attributes');
            $table->primary(['snapshot_id', 'evidence_id']);
            $table->index(['snapshot_id', 'detector'], 'lm_evidence_snapshot_detector');
            $table->index(['snapshot_id', 'file', 'start_line', 'end_line'], 'lm_evidence_snapshot_file_span');
        });

        $schema->create('lm_edge_evidence', function (Blueprint $table): void {
            $table->string('snapshot_id', 64);
            $table->string('edge_id', 64);
            $table->string('evidence_id', 64);
            $table->unique(['snapshot_id', 'edge_id', 'evidence_id'], 'lm_edge_evidence_unique');
            $table->index(['snapshot_id', 'evidence_id'], 'lm_edge_evidence_by_evidence');
        });

        $schema->create('lm_diagnostics', function (Blueprint $table): void {
            $table->id();
            $table->string('snapshot_id', 64);
            $table->string('code', 100);
            $table->string('phase', 100);
            $table->string('file', 500)->nullable();
            $table->unsignedInteger('start_line')->nullable();
            $table->unsignedInteger('end_line')->nullable();
            $table->text('message');
            $table->text('attributes');
            $table->index(['snapshot_id', 'code'], 'lm_diagnostics_snapshot_code');
            $table->index(['snapshot_id', 'phase'], 'lm_diagnostics_snapshot_phase');
            $table->index(['snapshot_id', 'file', 'start_line', 'end_line'], 'lm_diagnostics_snapshot_file_span');
        });

        $schema->create('lm_process_steps', function (Blueprint $table): void {
            $table->string('snapshot_id', 64);
            $table->string('process_id', 500);
            $table->unsignedInteger('ordinal');
            $table->string('step_id', 100);
            $table->string('node_id', 500)->nullable();
            $table->string('step_kind', 40);
            $table->string('boundary', 40);
            $table->text('evidence_ids');
            $table->text('attributes');
            $table->primary(['snapshot_id', 'process_id', 'step_id']);
            $table->unique(['snapshot_id', 'process_id', 'ordinal'], 'lm_process_steps_ordinal_unique');
            $table->index(['snapshot_id', 'node_id'], 'lm_process_steps_snapshot_node');
        });

        $schema->create('lm_runtime_sessions', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('snapshot_id', 64);
            $table->string('started_at', 64);
            $table->string('ended_at', 64)->nullable();
            $table->string('root_correlation_id', 64);
            $table->unsignedInteger('observation_count')->default(0);
            $table->boolean('truncated')->default(false);
            $table->index(['snapshot_id', 'started_at'], 'lm_runtime_sessions_snapshot_started');
            $table->index(['ended_at', 'started_at', 'id'], 'lm_runtime_sessions_ended');
        });

        $schema->create('lm_runtime_observations', function (Blueprint $table): void {
            $table->id();
            $table->string('session_id', 64);
            $table->string('correlation_id', 100);
            $table->string('parent_id', 100)->nullable();
            $table->string('observed_at', 64);
            $table->string('kind', 60);
            $table->string('source_node_id', 500)->nullable();
            $table->string('target_node_id', 500)->nullable();
            $table->double('duration_ms')->nullable();
            $table->boolean('success')->nullable();
            $table->text('attributes');
            $table->index(['session_id', 'observed_at', 'id'], 'lm_runtime_observations_session_time');
            // (session_id, source, target, kind) would exceed MySQL's 3072-byte
            // composite index limit; the session+source prefix is selective enough.
            $table->index(['session_id', 'source_node_id'], 'lm_runtime_observations_relation');
            $table->index(['session_id', 'correlation_id', 'parent_id'], 'lm_runtime_observations_correlation');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());

        foreach ([
            'lm_runtime_observations',
            'lm_runtime_sessions',
            'lm_process_steps',
            'lm_diagnostics',
            'lm_edge_evidence',
            'lm_evidence',
            'lm_edges',
            'lm_nodes',
            'lm_files',
            'lm_active_snapshot',
            'lm_snapshots',
        ] as $table) {
            $schema->dropIfExists($table);
        }
    }
};
