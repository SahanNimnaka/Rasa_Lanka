<?php

namespace App\Services\Annotation;

use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class B1ProcessRunner
{
    /**
     * @param  list<string>  $command
     * @param  array<string,string>|null  $env
     * @return array{exit_code:int|null,stdout:string,stderr:string,timed_out:bool,duration_ms:int}
     */
    public function run(array $command, string $cwd, int $timeoutSeconds, ?array $env = null): array
    {
        $started = hrtime(true);
        $process = new Process($command, $cwd, $env, null, $timeoutSeconds);
        $timedOut = false;

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            $timedOut = true;
            $process->stop(1, defined('SIGKILL') ? SIGKILL : 9);
        } catch (\Throwable $e) {
            throw new RuntimeException('B1 process execution failed: '.$e->getMessage(), 0, $e);
        }

        $durationMs = (int) ((hrtime(true) - $started) / 1_000_000);

        return [
            'exit_code' => $process->getExitCode(),
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
            'timed_out' => $timedOut,
            'duration_ms' => $durationMs,
        ];
    }
}
