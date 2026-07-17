<?php

namespace DNDark\LogicMap\Repositories\Sqlite;

use PDO;

final class SqliteSchema
{
    public const VERSION = 2;

    public function ensure(PDO $connection): void
    {
        foreach ($this->statements() as $statement) {
            $connection->exec($statement);
        }
    }

    /** @return list<string> */
    private function statements(): array
    {
        return [
            <<<'SQL'
CREATE TABLE IF NOT EXISTS lm_snapshots (
    id TEXT PRIMARY KEY,
    schema_version INTEGER NOT NULL,
    analysis_version TEXT NOT NULL,
    indexed_at TEXT NOT NULL,
    source_fingerprint TEXT NOT NULL,
    phase_metrics TEXT NOT NULL
)
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS lm_active_snapshot (
    singleton INTEGER PRIMARY KEY CHECK (singleton = 1),
    snapshot_id TEXT NOT NULL,
    FOREIGN KEY (snapshot_id) REFERENCES lm_snapshots(id) ON DELETE CASCADE
)
SQL,
            <<<'SQL'
CREATE TABLE IF NOT EXISTS lm_files (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    snapshot_id TEXT NOT NULL,
    path TEXT NOT NULL,
    content_hash TEXT NOT NULL,
    size INTEGER NOT NULL,
    FOREIGN KEY (snapshot_id) REFERENCES lm_snapshots(id) ON DELETE CASCADE
)
SQL,
            'CREATE UNIQUE INDEX IF NOT EXISTS lm_files_snapshot_path_unique ON lm_files(snapshot_id, path)',
            'CREATE INDEX IF NOT EXISTS lm_files_snapshot_content_hash ON lm_files(snapshot_id, content_hash)',
            <<<'SQL'
CREATE TABLE IF NOT EXISTS lm_nodes (
    snapshot_id TEXT NOT NULL,
    node_id TEXT NOT NULL,
    kind TEXT NOT NULL,
    name TEXT NOT NULL,
    qualified_name TEXT NULL,
    file TEXT NULL,
    start_line INTEGER NULL,
    end_line INTEGER NULL,
    attributes TEXT NOT NULL,
    PRIMARY KEY (snapshot_id, node_id),
    FOREIGN KEY (snapshot_id) REFERENCES lm_snapshots(id) ON DELETE CASCADE
)
SQL,
            'CREATE INDEX IF NOT EXISTS lm_nodes_snapshot_kind ON lm_nodes(snapshot_id, kind)',
            'CREATE INDEX IF NOT EXISTS lm_nodes_snapshot_qualified_name ON lm_nodes(snapshot_id, qualified_name)',
            'CREATE INDEX IF NOT EXISTS lm_nodes_snapshot_file_span ON lm_nodes(snapshot_id, file, start_line, end_line)',
            <<<'SQL'
CREATE TABLE IF NOT EXISTS lm_edges (
    snapshot_id TEXT NOT NULL,
    edge_id TEXT NOT NULL,
    source_id TEXT NOT NULL,
    target_id TEXT NOT NULL,
    type TEXT NOT NULL,
    site_key TEXT NOT NULL,
    PRIMARY KEY (snapshot_id, edge_id),
    FOREIGN KEY (snapshot_id, source_id) REFERENCES lm_nodes(snapshot_id, node_id) ON DELETE CASCADE,
    FOREIGN KEY (snapshot_id, target_id) REFERENCES lm_nodes(snapshot_id, node_id) ON DELETE CASCADE
)
SQL,
            'CREATE INDEX IF NOT EXISTS lm_edges_snapshot_source_type ON lm_edges(snapshot_id, source_id, type)',
            'CREATE INDEX IF NOT EXISTS lm_edges_snapshot_target_type ON lm_edges(snapshot_id, target_id, type)',
            <<<'SQL'
CREATE TABLE IF NOT EXISTS lm_evidence (
    snapshot_id TEXT NOT NULL,
    evidence_id TEXT NOT NULL,
    origin TEXT NOT NULL,
    detector TEXT NOT NULL,
    certainty TEXT NOT NULL,
    file TEXT NULL,
    start_line INTEGER NULL,
    end_line INTEGER NULL,
    expression TEXT NULL,
    condition_text TEXT NULL,
    attributes TEXT NOT NULL,
    PRIMARY KEY (snapshot_id, evidence_id),
    FOREIGN KEY (snapshot_id) REFERENCES lm_snapshots(id) ON DELETE CASCADE
)
SQL,
            'CREATE INDEX IF NOT EXISTS lm_evidence_snapshot_detector ON lm_evidence(snapshot_id, detector)',
            'CREATE INDEX IF NOT EXISTS lm_evidence_snapshot_file_span ON lm_evidence(snapshot_id, file, start_line, end_line)',
            <<<'SQL'
CREATE TABLE IF NOT EXISTS lm_edge_evidence (
    snapshot_id TEXT NOT NULL,
    edge_id TEXT NOT NULL,
    evidence_id TEXT NOT NULL,
    FOREIGN KEY (snapshot_id, edge_id) REFERENCES lm_edges(snapshot_id, edge_id) ON DELETE CASCADE,
    FOREIGN KEY (snapshot_id, evidence_id) REFERENCES lm_evidence(snapshot_id, evidence_id) ON DELETE CASCADE
)
SQL,
            'CREATE UNIQUE INDEX IF NOT EXISTS lm_edge_evidence_unique ON lm_edge_evidence(snapshot_id, edge_id, evidence_id)',
            'CREATE INDEX IF NOT EXISTS lm_edge_evidence_by_evidence ON lm_edge_evidence(snapshot_id, evidence_id)',
            <<<'SQL'
CREATE TABLE IF NOT EXISTS lm_diagnostics (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    snapshot_id TEXT NOT NULL,
    code TEXT NOT NULL,
    phase TEXT NOT NULL,
    file TEXT NULL,
    start_line INTEGER NULL,
    end_line INTEGER NULL,
    message TEXT NOT NULL,
    attributes TEXT NOT NULL,
    FOREIGN KEY (snapshot_id) REFERENCES lm_snapshots(id) ON DELETE CASCADE
)
SQL,
            'CREATE INDEX IF NOT EXISTS lm_diagnostics_snapshot_code ON lm_diagnostics(snapshot_id, code)',
            'CREATE INDEX IF NOT EXISTS lm_diagnostics_snapshot_phase ON lm_diagnostics(snapshot_id, phase)',
            'CREATE INDEX IF NOT EXISTS lm_diagnostics_snapshot_file_span ON lm_diagnostics(snapshot_id, file, start_line, end_line)',
            <<<'SQL'
CREATE TABLE IF NOT EXISTS lm_process_steps (
    snapshot_id TEXT NOT NULL,
    process_id TEXT NOT NULL,
    ordinal INTEGER NOT NULL,
    step_id TEXT NOT NULL,
    node_id TEXT NULL,
    step_kind TEXT NOT NULL,
    boundary TEXT NOT NULL,
    evidence_ids TEXT NOT NULL,
    attributes TEXT NOT NULL,
    PRIMARY KEY (snapshot_id, process_id, step_id),
    UNIQUE (snapshot_id, process_id, ordinal),
    FOREIGN KEY (snapshot_id) REFERENCES lm_snapshots(id) ON DELETE CASCADE,
    FOREIGN KEY (snapshot_id, process_id) REFERENCES lm_nodes(snapshot_id, node_id) ON DELETE CASCADE,
    FOREIGN KEY (snapshot_id, node_id) REFERENCES lm_nodes(snapshot_id, node_id) ON DELETE CASCADE
)
SQL,
            'CREATE INDEX IF NOT EXISTS lm_process_steps_snapshot_process ON lm_process_steps(snapshot_id, process_id, ordinal)',
            'CREATE INDEX IF NOT EXISTS lm_process_steps_snapshot_node ON lm_process_steps(snapshot_id, node_id)',
            <<<'SQL'
CREATE TABLE IF NOT EXISTS lm_runtime_sessions (
    id TEXT PRIMARY KEY,
    snapshot_id TEXT NOT NULL,
    started_at TEXT NOT NULL,
    ended_at TEXT NULL,
    root_correlation_id TEXT NOT NULL,
    observation_count INTEGER NOT NULL DEFAULT 0,
    truncated INTEGER NOT NULL DEFAULT 0 CHECK (truncated IN (0, 1)),
    FOREIGN KEY (snapshot_id) REFERENCES lm_snapshots(id) ON DELETE CASCADE
)
SQL,
            'CREATE INDEX IF NOT EXISTS lm_runtime_sessions_snapshot_started ON lm_runtime_sessions(snapshot_id, started_at)',
            <<<'SQL'
CREATE TABLE IF NOT EXISTS lm_runtime_observations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id TEXT NOT NULL,
    correlation_id TEXT NOT NULL,
    parent_id TEXT NULL,
    observed_at TEXT NOT NULL,
    kind TEXT NOT NULL,
    source_node_id TEXT NULL,
    target_node_id TEXT NULL,
    duration_ms REAL NULL,
    success INTEGER NULL CHECK (success IS NULL OR success IN (0, 1)),
    attributes TEXT NOT NULL,
    FOREIGN KEY (session_id) REFERENCES lm_runtime_sessions(id) ON DELETE CASCADE
)
SQL,
            'CREATE INDEX IF NOT EXISTS lm_runtime_observations_session_time ON lm_runtime_observations(session_id, observed_at, id)',
            'CREATE INDEX IF NOT EXISTS lm_runtime_observations_session_relation ON lm_runtime_observations(session_id, source_node_id, target_node_id, kind)',
            'CREATE INDEX IF NOT EXISTS lm_runtime_observations_correlation ON lm_runtime_observations(session_id, correlation_id, parent_id)',
        ];
    }
}
