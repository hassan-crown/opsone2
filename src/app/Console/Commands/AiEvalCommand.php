<?php

namespace App\Console\Commands;

use App\Ai\AiCall;
use App\Ai\AiGateway;
use App\Ai\AiOutcome;
use App\Ai\AiTask;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use JsonException;

/**
 * The Arabic evaluation set (plan §3.6): synthetic examples only (Q-96), one JSONL file per axis in
 * tests/AiEval/fixtures. Run by hand because a real driver costs money; never part of CI.
 *
 * With the default `log` driver nothing is sent: the run only checks that every example is well-formed. With
 * `anthropic` the command refuses unless --confirm-cost is given. Evaluation calls skip facility switches and
 * credits but are redacted, validated and recorded in ai_usage like any call.
 *
 * Line format: {"id", "task", "system", "instruction", "schema", "facts"?, "untrusted"?, "known"?,
 *               "expect": {"outcome"?: "ok", "equals"?: {"dot.path": value}, "not_contains"?: [text]}}
 */
#[Signature('ai:eval {axis? : Fixture file name without .jsonl (all when omitted)} {--limit=0 : At most N examples per axis} {--confirm-cost : Required when the driver sends requests}')]
#[Description('Run the AI evaluation examples (manual; costs money with a real driver)')]
class AiEvalCommand extends Command
{
    public function handle(AiGateway $gateway): int
    {
        $driver = $gateway->driver();
        if (! in_array($driver, ['log', 'fake'], true) && ! $this->option('confirm-cost')) {
            $this->components->error("Driver «{$driver}» sends real requests and costs money. Re-run with --confirm-cost.");

            return self::FAILURE;
        }

        $root = (string) config('ai.eval_path');
        $axis = $this->argument('axis');
        $files = is_string($axis) && $axis !== '' ? [$root.'/'.basename($axis).'.jsonl'] : (glob($root.'/*.jsonl') ?: []);
        $limit = max(0, (int) $this->option('limit'));

        $failures = 0;
        $rows = [];
        foreach ($files as $file) {
            if (! is_file($file)) {
                $this->components->error('No fixture file '.basename($file).'.');

                return self::FAILURE;
            }

            $lines = array_values(array_filter(array_map('trim', file($file) ?: []), fn (string $l) => $l !== '' && ! str_starts_with($l, '//')));
            foreach ($limit > 0 ? array_slice($lines, 0, $limit) : $lines as $n => $line) {
                $label = basename($file, '.jsonl').'#'.($n + 1);
                try {
                    $example = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
                    [$call, $expect] = $this->parse(is_array($example) ? $example : []);
                    $label = basename($file, '.jsonl').':'.($example['id'] ?? $n + 1);
                } catch (JsonException|\InvalidArgumentException $e) {
                    $rows[] = [$label, '—', 'BAD FIXTURE: '.$e->getMessage()];
                    $failures++;

                    continue;
                }

                $result = $gateway->run($call, evaluation: true);
                if ($result->outcome === AiOutcome::NotSent) {
                    $rows[] = [$label, $result->outcome->value, 'well-formed (not sent)'];

                    continue;
                }

                $problems = $this->check($result->outcome, $result->output, $expect);
                $failures += $problems === [] ? 0 : 1;
                $rows[] = [$label, $result->outcome->value, $problems === [] ? 'pass' : implode('; ', $problems)];
            }
        }

        $this->table(['example', 'outcome', 'result'], $rows);
        $this->components->info(count($rows).' example(s), '.$failures.' failure(s), driver '.$driver.'.');

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<mixed>  $e
     * @return array{0: AiCall, 1: array<string, mixed>}
     */
    private function parse(array $e): array
    {
        $task = AiTask::tryFrom((string) ($e['task'] ?? '')) ?? throw new \InvalidArgumentException('unknown task');
        foreach (['system', 'instruction'] as $key) {
            if (! is_string($e[$key] ?? null) || $e[$key] === '') {
                throw new \InvalidArgumentException("{$key} missing");
            }
        }
        if (! is_array($e['schema'] ?? null) || ! is_array($e['expect'] ?? null)) {
            throw new \InvalidArgumentException('schema and expect are required');
        }

        return [new AiCall(
            task: $task,
            system: $e['system'],
            instruction: $e['instruction'],
            schema: $e['schema'],
            facts: is_array($e['facts'] ?? null) ? $e['facts'] : [],
            untrusted: array_values(array_filter((array) ($e['untrusted'] ?? []), 'is_string')),
            known: is_array($e['known'] ?? null) ? $e['known'] : [],
        ), $e['expect']];
    }

    /**
     * @param  array<string, mixed>|null  $output
     * @param  array<string, mixed>  $expect
     * @return list<string>
     */
    private function check(AiOutcome $outcome, ?array $output, array $expect): array
    {
        $problems = [];
        $wanted = (string) ($expect['outcome'] ?? AiOutcome::Ok->value);
        if ($outcome->value !== $wanted) {
            $problems[] = "outcome {$outcome->value}, expected {$wanted}";
        }

        foreach ((array) ($expect['equals'] ?? []) as $path => $value) {
            if (Arr::get($output ?? [], (string) $path) !== $value) {
                $problems[] = "{$path} differs";
            }
        }

        $text = (string) json_encode($output ?? [], JSON_UNESCAPED_UNICODE);
        foreach ((array) ($expect['not_contains'] ?? []) as $needle) {
            if (is_string($needle) && $needle !== '' && mb_stripos($text, $needle) !== false) {
                $problems[] = 'contains a forbidden text';
            }
        }

        return $problems;
    }
}
