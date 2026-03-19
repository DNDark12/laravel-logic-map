<?php

namespace dndark\LogicMap\Analysis\Support;

class ModuleExtractor
{
    /** @var list<string> */
    protected const GENERIC_SEGMENTS = [
        'app', 'src', 'domain', 'http', 'controllers', 'controller', 'services', 'service',
        'repositories', 'repository', 'jobs', 'job', 'events', 'event', 'listeners', 'listener',
        'providers', 'provider', 'models', 'model', 'support', 'contracts', 'console', 'commands',
        'dndark', 'logicmap', 'logic', 'map', 'analysis', 'projectors', 'runtime', 'resolvers',
    ];

    /** @var list<string> */
    protected const CLASS_SUFFIXES = [
        'Controller', 'Service', 'Repository', 'Job', 'Event', 'Listener', 'Command',
        'Model', 'Handler', 'Manager', 'Projector', 'Resolver', 'Provider', 'Request',
        'Resource', 'Policy', 'Observer',
    ];

    public static function moduleOf(string $idOrClass): string
    {
        $raw = trim($idOrClass);
        if ($raw === '') {
            return 'Core';
        }

        if (str_starts_with($raw, 'route:')) {
            return 'Route';
        }

        $normalized = self::normalize($raw);
        if ($normalized === '') {
            return 'Core';
        }

        $parts = array_values(array_filter(explode('\\', $normalized), fn($p) => $p !== ''));
        if (empty($parts)) {
            return 'Core';
        }

        foreach ($parts as $part) {
            $clean = self::cleanSegment($part);
            if ($clean === '') {
                continue;
            }

            if (!in_array(strtolower($clean), self::GENERIC_SEGMENTS, true)) {
                return $clean;
            }
        }

        // Fallback to class basename (strip technical suffixes).
        $last = self::cleanSegment(end($parts) ?: '');
        return $last !== '' ? $last : 'Core';
    }

    protected static function normalize(string $value): string
    {
        $value = preg_replace('/^(class|method|route):/i', '', $value) ?? $value;
        $value = preg_replace('/@.+$/', '', $value) ?? $value;
        $value = ltrim($value, '\\/');

        return trim($value);
    }

    protected static function cleanSegment(string $segment): string
    {
        $segment = trim($segment, "\\ \t\n\r\0\x0B");
        if ($segment === '') {
            return '';
        }

        foreach (self::CLASS_SUFFIXES as $suffix) {
            if (str_ends_with($segment, $suffix) && strlen($segment) > strlen($suffix)) {
                $segment = substr($segment, 0, -strlen($suffix));
                break;
            }
        }

        $segment = preg_replace('/[^A-Za-z0-9_]/', '', $segment) ?? $segment;
        return trim($segment);
    }
}
