<?php

namespace DNDark\LogicMap\Support;

/**
 * Snapshot storage schema version. Participates in snapshot ids and source
 * fingerprints; bump when the persisted shape of a snapshot changes.
 */
final class SchemaVersion
{
    public const VERSION = 2;
}
