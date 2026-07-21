<?php

namespace DNDark\LogicMap\Support\Git;

use InvalidArgumentException;
use RuntimeException;

class NativeGitCommandRunner
{
    /** @return array{stdout: string, stderr: string, exit_code: int} */
    public function run(array $argv, string $workingDirectory, int $timeoutMs): array
    {
        if ($argv === [] || $timeoutMs < 1 || ! is_dir($workingDirectory)) {
            throw new InvalidArgumentException('Git commands require argv, a repository directory, and a timeout.');
        }

        foreach ($argv as $argument) {
            if (! is_string($argument) || str_contains($argument, "\0")) {
                throw new InvalidArgumentException('Git argv entries must be safe strings.');
            }
        }

        $pipes = [];
        $process = proc_open(
            $argv,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $workingDirectory,
            null,
            ['bypass_shell' => true],
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start Git process.');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $started = hrtime(true);
        $exitCode = null;

        try {
            while (true) {
                $stdout .= stream_get_contents($pipes[1]) ?: '';
                $stderr .= stream_get_contents($pipes[2]) ?: '';
                $status = proc_get_status($process);

                if (! $status['running']) {
                    $exitCode = (int) $status['exitcode'];
                    break;
                }

                if ((hrtime(true) - $started) / 1_000_000 > $timeoutMs) {
                    proc_terminate($process);
                    throw new RuntimeException('Git command timed out.');
                }

                usleep(10_000);
            }

            $stdout .= stream_get_contents($pipes[1]) ?: '';
            $stderr .= stream_get_contents($pipes[2]) ?: '';
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            $closed = proc_close($process);

            if ($exitCode === null && $closed >= 0) {
                $exitCode = $closed;
            }
        }

        return [
            'stdout' => $stdout,
            'stderr' => $stderr,
            'exit_code' => $exitCode ?? 1,
        ];
    }
}
