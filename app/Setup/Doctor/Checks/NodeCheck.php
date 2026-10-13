<?php

declare(strict_types=1);

namespace App\Setup\Doctor\Checks;

use App\Setup\Doctor\Check;
use App\Setup\Doctor\CheckResult;
use App\Setup\Doctor\DoctorContext;
use App\Setup\Steps\FrontendStep;
use Illuminate\Process\Exceptions\ProcessTimedOutException;

/** §5.4: Node on the toolchain PATH satisfies Vite's ^20.19 || >=22.12 (setup step 5's own rule); only warns. */
final readonly class NodeCheck implements Check
{
    public function key(): string
    {
        return 'node';
    }

    public function label(): string
    {
        return __('Node');
    }

    public function run(DoctorContext $context): CheckResult
    {
        $fix = __('Install Node ^20.19 or >=22.12');
        $node = $context->toolchain->which('node');

        if ($node === null) {
            return CheckResult::warn(__('Node was not found'), $fix);
        }

        try {
            $version = trim($context->process(10)->run([$node, '-v'])->output());
        } catch (ProcessTimedOutException) {
            $version = '';
        }

        if (FrontendStep::nodeSatisfies($version)) {
            return CheckResult::pass(__('Node :version', ['version' => $version]));
        }

        return CheckResult::warn(__('Node :version is not supported', ['version' => $version === '' ? '?' : $version]), $fix);
    }

    public function fix(DoctorContext $context): ?CheckResult
    {
        return null;
    }
}
