<?php

namespace dndark\LogicMap\Analysis\Support;

class IntentExtractor
{
    protected static array $knownWordsCache = [];

    public static function extractFromMethod(string $method, string $class = ''): array
    {
        $actions = self::actionMap();
        $domains = self::domainMap();
        $results = self::resultMap();

        $mw = self::splitWords($method);

        $rawBase = basename(str_replace('\\', '/', $class));
        $suffixes = ['Controller', 'Service', 'Repository', 'Manager', 'Handler', 'Job', 'Listener', 'Event', 'Notification', 'Observer', 'Policy', 'Middleware', 'Command', 'Seeder', 'Factory', 'Resource', 'Request'];

        $cw = array_filter(self::splitWords($rawBase), function ($w) use ($suffixes) {
            return !in_array(ucfirst($w), $suffixes);
        });
        $cw = array_values($cw);

        $action = '';
        $actionKey = '';
        $actionIndex = -1;

        foreach ($mw as $i => $w) {
            if (isset($actions[$w])) {
                $action = $actions[$w];
                $actionKey = $w;
                $actionIndex = $i;
                break;
            }
        }

        $stop = ['for', 'by', 'with', 'from', 'to', 'the', 'a', 'an', 'of', 'in', 'on', 'at', 'per', 'and', 'or', 'not', 'all', 'new', 'bulk'];

        $methodDomains = [];
        $classDomains = [];
        $seen = [];

        foreach ($mw as $i => $w) {
            if ($i === $actionIndex || in_array($w, $stop) || isset($seen[$w])) continue;
            $seen[$w] = true;
            if (isset($domains[$w])) $methodDomains[] = $domains[$w];
        }

        if (empty($methodDomains)) {
            foreach ($mw as $i => $w) {
                if ($i === $actionIndex || isset($seen[$w])) continue;
                $seen[$w] = true;
                if (isset($domains[$w])) $methodDomains[] = $domains[$w];
            }
        }

        foreach ($cw as $w) {
            if (in_array($w, $stop) || isset($seen[$w])) continue;
            $seen[$w] = true;
            if (isset($domains[$w])) $classDomains[] = $domains[$w];
        }

        $allDomains = array_values(array_unique(array_merge($methodDomains, $classDomains)));
        $domainStr = implode(' ', array_slice($allDomains, 0, 3));

        $resultStr = $results[$actionKey] ?? ($action ? strtolower($action) . 'd' : '');

        $subject = $domainStr;
        if (!$subject && !empty($classDomains)) {
            $subject = implode(' ', array_slice($classDomains, 0, 3));
        }
        if (!$subject) {
            $rawWords = array_filter($cw, fn($w) => !in_array($w, $stop) && strlen($w) > 2);
            $subject = implode(' ', array_slice(array_values($rawWords), 0, 3));
        }

        if ($action && $subject) {
            $short = $action . ' ' . $subject;
        } elseif ($action) {
            $classFallback = implode(' ', array_map('ucfirst', array_slice($cw, 0, 2)));
            $short = $action . ($classFallback ? ' ' . $classFallback : '');
        } elseif ($subject) {
            $short = 'Manage ' . $subject;
        } else {
            $humanMethod = implode(' ', array_map('ucfirst', $mw));
            $classCtx = implode(' ', array_map('ucfirst', array_slice($cw, 0, 2)));
            $short = $classCtx ? $humanMethod . ' ' . $classCtx : $humanMethod;
        }

        if (!$short) {
            $short = $rawBase . '::' . $method;
        }

        return [
            'action' => $action,
            'domain' => $domainStr,
            'result' => $resultStr,
            'short' => $short,
            'trigger' => self::triggerMap($class),
        ];
    }

    public static function extractFromRoute(string $uri, string $verb, string $fallbackController = ''): array
    {
        $actions = self::actionMap();
        $domains = self::domainMap();

        $segs = array_filter(explode('/', $uri), fn($s) => $s !== '' && !preg_match('/^\{.+\}$/', $s));
        $words = [];
        foreach ($segs as $seg) {
            foreach (preg_split('/[_\-]/', $seg) as $w) {
                $words[] = strtolower($w);
            }
        }

        $action = '';
        $actionIndex = -1;
        $actionKey = '';
        foreach ($words as $i => $w) {
            if (isset($actions[$w])) {
                $action = $actions[$w];
                $actionKey = $w;
                $actionIndex = $i;
                break;
            }
        }

        $dp = [];
        $seen = [];
        foreach ($words as $i => $w) {
            if ($i === $actionIndex || isset($seen[$w])) continue;
            $seen[$w] = true;
            if (isset($domains[$w])) $dp[] = $domains[$w];
        }

        $vmap = ['GET' => 'View', 'POST' => 'Create', 'PUT' => 'Update', 'PATCH' => 'Update', 'DELETE' => 'Delete'];
        $vb = $vmap[strtoupper($verb)] ?? '';

        $domainStr = implode(' ', array_slice($dp, 0, 3));

        if ($action && $domainStr) {
            $short = $action . ' ' . $domainStr;
        } elseif ($action) {
            $short = $action;
        } elseif ($domainStr) {
            $short = ($vb ?: 'Manage') . ' ' . $domainStr;
        } else {
            $short = $vb ?: 'Handle request';
            if ($fallbackController) {
                $short .= ' (' . basename(str_replace('\\', '/', $fallbackController)) . ')';
            }
        }

        return [
            'action' => $action ?: $vb,
            'domain' => $domainStr,
            'result' => 'response generated',
            'short' => $short,
            'trigger' => 'HTTP request',
        ];
    }

    public static function splitWords(string $name): array
    {
        // Handle camelCase, PascalCase, snake_case, and kebab-case
        // Also split around numbers and common logic map delimiters
        $parts = preg_split('/(?<=[a-z])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])|[_\-\s0-9]+/', $name, -1, PREG_SPLIT_NO_EMPTY);

        $result = [];
        foreach ($parts as $part) {
            $lower = strtolower($part);
            if (strlen($lower) <= 3) {
                // Keep short acronyms or small words as-is
                $result[] = $lower;
            } else {
                // Attempt dictionary splitting for concatenated words (e.g. "shopeemanager" -> "shopee", "manager")
                $dictSplit = self::dictSplitWord($lower);
                if (!empty($dictSplit)) {
                    foreach ($dictSplit as $w) $result[] = $w;
                } else {
                    $result[] = $lower;
                }
            }
        }
        return array_values(array_filter($result, fn($w) => strlen($w) > 1));
    }

    protected static function dictSplitWord(string $s): array
    {
        if ($s === '') return [];
        $known = self::knownWords();
        foreach ($known as $word) {
            if (str_starts_with($s, $word)) {
                $rest = substr($s, strlen($word));
                if ($rest === '') return [$word];
                $sub = self::dictSplitWord($rest);
                if (!empty($sub)) {
                    return array_merge([$word], $sub);
                }
            }
        }
        return [$s];
    }

    protected static function triggerMap(string $class): string
    {
        if (str_contains($class, '\\Jobs\\')) return 'Queue worker';
        if (str_contains($class, '\\Events\\') || str_contains($class, '\\Listeners\\')) return 'Event dispatched';
        if (str_contains($class, '\\Controllers\\')) return 'HTTP request';
        if (str_contains($class, '\\Services\\')) return 'Controller or Service call';
        if (str_contains($class, '\\Console\\')) return 'Cron / CLI command';
        return 'Internal call';
    }

    protected static function actionMap(): array
    {
        return [
            'toggle' => 'Toggle', 'enable' => 'Enable', 'disable' => 'Disable', 'activate' => 'Activate', 'deactivate' => 'Deactivate',
            'lock' => 'Lock', 'unlock' => 'Unlock', 'pause' => 'Pause', 'resume' => 'Resume', 'start' => 'Start', 'stop' => 'Stop',
            'open' => 'Open', 'close' => 'Close', 'sync' => 'Sync', 'import' => 'Import', 'export' => 'Export', 'upload' => 'Upload',
            'download' => 'Download', 'generate' => 'Generate', 'calculate' => 'Calculate', 'compute' => 'Compute', 'recalculate' => 'Recalculate',
            'parse' => 'Parse', 'transform' => 'Transform', 'convert' => 'Convert', 'merge' => 'Merge', 'split' => 'Split', 'refresh' => 'Refresh',
            'reset' => 'Reset', 'restore' => 'Restore', 'archive' => 'Archive', 'migrate' => 'Migrate', 'crawl' => 'Crawl', 'pull' => 'Pull',
            'push' => 'Push', 'send' => 'Send', 'notify' => 'Notify', 'broadcast' => 'Broadcast', 'publish' => 'Publish', 'unpublish' => 'Unpublish',
            'dispatch' => 'Dispatch', 'emit' => 'Emit', 'process' => 'Process', 'execute' => 'Execute', 'handle' => 'Handle', 'run' => 'Run',
            'perform' => 'Perform', 'apply' => 'Apply', 'check' => 'Check', 'verify' => 'Verify', 'approve' => 'Approve', 'reject' => 'Reject',
            'submit' => 'Submit', 'confirm' => 'Confirm', 'complete' => 'Complete', 'finalize' => 'Finalize', 'cancel' => 'Cancel', 'schedule' => 'Schedule',
            'register' => 'Register', 'login' => 'Login', 'logout' => 'Logout', 'invite' => 'Invite', 'assign' => 'Assign', 'revoke' => 'Revoke',
            'grant' => 'Grant', 'pay' => 'Pay', 'refund' => 'Refund', 'charge' => 'Charge', 'transfer' => 'Transfer', 'search' => 'Search',
            'report' => 'Report', 'track' => 'Track', 'monitor' => 'Monitor', 'update' => 'Update', 'remove' => 'Remove', 'attach' => 'Attach',
            'detach' => 'Detach', 'connect' => 'Connect', 'disconnect' => 'Disconnect', 'deploy' => 'Deploy', 'retry' => 'Retry', 'rollback' => 'Rollback',
            'reprocess' => 'Reprocess', 'enqueue' => 'Enqueue', 'aggregate' => 'Aggregate', 'collect' => 'Collect', 'fetch' => 'Fetch',
            'validate' => 'Validate', 'sanitize' => 'Sanitize', 'normalize' => 'Normalize', 'encrypt' => 'Encrypt', 'decrypt' => 'Decrypt',
            'sign' => 'Sign', 'flag' => 'Flag', 'mark' => 'Mark', 'tag' => 'Tag', 'clone' => 'Clone', 'duplicate' => 'Duplicate', 'audit' => 'Audit',
            'record' => 'Record', 'detect' => 'Detect', 'trigger' => 'Trigger', 'claim' => 'Claim', 'release' => 'Release', 'match' => 'Match',
            'rank' => 'Rank', 'score' => 'Score', 'estimate' => 'Estimate', 'resolve' => 'Resolve', 'escalate' => 'Escalate',
            'index' => 'List', 'show' => 'View', 'create' => 'Create', 'store' => 'Save', 'edit' => 'Edit', 'destroy' => 'Delete'
        ];
    }

    protected static function domainMap(): array
    {
        return [
            'alert' => 'alert', 'rule' => 'rule', 'threshold' => 'threshold', 'lazada' => 'Lazada', 'shopee' => 'Shopee', 'tiki' => 'Tiki',
            'tiktok' => 'TikTok', 'product' => 'product', 'order' => 'order', 'inventory' => 'inventory', 'stock' => 'stock', 'price' => 'price',
            'sku' => 'SKU', 'variant' => 'variant', 'category' => 'category', 'user' => 'user', 'partner' => 'partner', 'customer' => 'customer',
            'seller' => 'seller', 'manager' => 'manager', 'admin' => 'admin', 'telegram' => 'Telegram', 'email' => 'email', 'sms' => 'SMS',
            'webhook' => 'webhook', 'notification' => 'notification', 'payment' => 'payment', 'invoice' => 'invoice', 'transaction' => 'transaction',
            'wallet' => 'wallet', 'commission' => 'commission', 'refund' => 'refund', 'discount' => 'discount', 'promotion' => 'promotion',
            'campaign' => 'campaign', 'setting' => 'setting', 'config' => 'config', 'token' => 'token', 'shop' => 'shop', 'store' => 'store',
            'platform' => 'platform', 'channel' => 'channel', 'warehouse' => 'warehouse', 'shipping' => 'shipping', 'delivery' => 'delivery',
            'status' => 'status', 'data' => 'data', 'link' => 'link', 'sync' => 'sync', 'schedule' => 'schedule', 'log' => 'log', 'queue' => 'queue',
            'job' => 'job', 'report' => 'report', 'rating' => 'rating', 'batch' => 'batch', 'aggregate' => 'aggregate', 'metric' => 'metric',
            'stat' => 'stat', 'analytic' => 'analytic', 'content' => 'content', 'media' => 'media', 'image' => 'image', 'file' => 'file',
            'currency' => 'currency', 'rate' => 'rate', 'affiliate' => 'affiliate', 'click' => 'click', 'conversion' => 'conversion',
            'revenue' => 'revenue', 'cost' => 'cost', 'budget' => 'budget', 'permission' => 'permission', 'role' => 'role', 'access' => 'access',
            'api' => 'API', 'connection' => 'connection', 'endpoint' => 'endpoint', 'credential' => 'credential', 'secret' => 'secret',
            'brand' => 'brand', 'vendor' => 'vendor', 'supplier' => 'supplier', 'keyword' => 'keyword', 'feed' => 'feed', 'stream' => 'stream',
            'period' => 'period', 'limit' => 'limit', 'quota' => 'quota', 'address' => 'address', 'region' => 'region'
        ];
    }

    protected static function resultMap(): array
    {
        return [
            'toggle' => 'state changed', 'enable' => 'enabled', 'disable' => 'disabled', 'activate' => 'activated', 'deactivate' => 'deactivated',
            'lock' => 'locked', 'unlock' => 'unlocked', 'send' => 'message sent', 'notify' => 'notification sent', 'broadcast' => 'broadcast sent',
            'dispatch' => 'job queued', 'sync' => 'data synced', 'import' => 'data imported', 'export' => 'file exported', 'generate' => 'generated',
            'calculate' => 'calculated', 'recalculate' => 'recalculated', 'process' => 'processed', 'execute' => 'executed', 'handle' => 'handled',
            'approve' => 'approved', 'reject' => 'rejected', 'cancel' => 'cancelled', 'complete' => 'completed', 'confirm' => 'confirmed',
            'schedule' => 'scheduled', 'publish' => 'published', 'unpublish' => 'unpublished', 'archive' => 'archived', 'restore' => 'restored',
            'reset' => 'reset', 'refresh' => 'refreshed', 'migrate' => 'migrated', 'pay' => 'payment processed', 'refund' => 'refund issued',
            'charge' => 'charged', 'transfer' => 'transferred', 'register' => 'registered', 'invite' => 'invitation sent', 'assign' => 'assigned',
            'revoke' => 'revoked', 'grant' => 'access granted', 'update' => 'updated', 'remove' => 'removed', 'crawl' => 'data collected',
            'pull' => 'data pulled', 'push' => 'data pushed', 'verify' => 'verified', 'check' => 'checked', 'retry' => 'retried', 'rollback' => 'rolled back',
            'store' => 'saved', 'destroy' => 'deleted', 'edit' => 'form loaded'
        ];
    }

    protected static function knownWords(): array
    {
        if (!empty(self::$knownWordsCache)) return self::$knownWordsCache;

        $words = [
            'recalculate', 'reprocess', 'deactivate', 'unpublish', 'disconnect', 'authenticate',
            'synchronize', 'initialize', 'unsubscribe', 'acknowledge', 'validateRequest',
            'normalize', 'sanitize', 'aggregate', 'calculate', 'broadcast', 'duplicate',
            'subscribe', 'transform', 'distribute', 'configure', 'unarchive', 'complete',
            'escalate', 'generate', 'activate', 'schedule', 'validate', 'download', 'transfer',
            'rollback', 'dispatch', 'register', 'retrieve', 'forecast', 'evaluate', 'analyze',
            'estimate', 'allocate', 'revocate', 'finalize', 'automate', 'truncate', 'reconcile',
            'process', 'execute', 'collect', 'archive', 'publish', 'confirm', 'approve', 'refresh',
            'restore', 'monitor', 'resolve', 'deliver', 'compile', 'convert', 'compare', 'balance',
            'request', 'decline', 'suspend', 'encrypt', 'decrypt', 'extract', 'migrate', 'capture',
            'promote', 'demote', 'publish', 'reserve', 'release', 'acquire', 'consume', 'produce',
            'toggle', 'enable', 'disable', 'cancel', 'submit', 'notify', 'record', 'detect', 'assign',
            'reject', 'verify', 'update', 'remove', 'attach', 'detach', 'import', 'export', 'upload',
            'deploy', 'search', 'report', 'refund', 'charge', 'invite', 'follow', 'unlink', 'relink',
            'create', 'delete', 'revoke', 'fetch', 'grant', 'track', 'audit', 'start', 'apply',
            'queue', 'close', 'retry', 'score', 'match', 'claim', 'merge', 'clone', 'share', 'relay',
            'reset', 'check', 'login', 'logout', 'pause', 'resume', 'label', 'trigger', 'sync',
            'send', 'flag', 'mark', 'sign', 'hash', 'rank', 'copy', 'test', 'link', 'lock', 'open',
            'scan', 'run', 'pay', 'ban', 'add', 'pin', 'log',
            'telegramconfig', 'configuration', 'notification', 'commission',
            'integration', 'conversion', 'connection', 'permission', 'credential', 'certificate',
            'transaction', 'invitation', 'completion', 'inspection', 'escalation',
            'subscription', 'publication', 'organization', 'registration', 'authorization',
            'authentication', 'synchronization', 'reconciliation',
            'dashboard', 'inventory', 'threshold', 'campaign', 'platform', 'category',
            'attribute', 'analytics', 'promotion', 'affiliate', 'referral', 'placement',
            'warehouse', 'shipment', 'fulfilment', 'settlement', 'withdrawal', 'onboarding',
            'payment', 'product', 'partner', 'setting', 'webhook', 'profile', 'session',
            'variant', 'customer', 'supplier', 'voucher', 'discount', 'feedback', 'location',
            'comment', 'address', 'invoice', 'content', 'version', 'channel', 'segment',
            'audience', 'shopee', 'lazada', 'tiktok', 'sendo', 'telegram', 'strategy', 'workflow',
            'telegram', 'config', 'status', 'report', 'budget', 'period', 'metric', 'stream',
            'source', 'target', 'revenue', 'region', 'record', 'rating', 'policy', 'option',
            'medium', 'layout', 'filter', 'expiry', 'device', 'coupon', 'client', 'result',
            'member', 'method', 'module', 'notice', 'output', 'prefix', 'review', 'schema',
            'sender', 'signal', 'socket', 'sprint', 'access', 'action', 'agenda', 'amount',
            'branch', 'bundle', 'choice', 'column', 'cursor', 'detail', 'domain', 'driver',
            'entity', 'engine', 'format', 'handler', 'header', 'health', 'import', 'index',
            'insight', 'intent', 'logger', 'mapping', 'message', 'network', 'object', 'packet',
            'parser', 'phase', 'plugin', 'portal', 'process', 'profile', 'project', 'prompt',
            'queue', 'query', 'quota', 'range', 'reason', 'resource', 'response', 'route',
            'rules', 'scope', 'script', 'search', 'service', 'table', 'target', 'tenant',
            'ticket', 'token', 'topic', 'trace', 'trigger', 'upload', 'user', 'value', 'vector',
            'wallet', 'widget', 'window', 'secret', 'batch', 'brand', 'click', 'email', 'event',
            'image', 'level', 'limit', 'media', 'model', 'order', 'price', 'queue', 'alert',
            'link', 'rule', 'role', 'feed', 'file', 'item', 'list', 'node', 'page', 'rate',
            'shop', 'site', 'sku', 'sms', 'stat', 'step', 'sync', 'tag', 'task', 'type', 'view',
            'api', 'sdk', 'job', 'url', 'key',
        ];

        usort($words, fn($a, $b) => strlen($b) - strlen($a));
        self::$knownWordsCache = $words;

        return self::$knownWordsCache;
    }
}
