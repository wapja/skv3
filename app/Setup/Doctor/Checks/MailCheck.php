<?php

declare(strict_types=1);

namespace App\Setup\Doctor\Checks;

use App\Setup\Doctor\Check;
use App\Setup\Doctor\CheckResult;
use App\Setup\Doctor\DoctorContext;

/** §5.4: with MAIL_MAILER=smtp, something accepts a TCP connection on MAIL_HOST:MAIL_PORT within a second. */
final readonly class MailCheck implements Check
{
    public function key(): string
    {
        return 'mail';
    }

    public function label(): string
    {
        return __('Mail');
    }

    public function run(DoctorContext $context): CheckResult
    {
        $env = $context->env();
        $mailer = $env->get('MAIL_MAILER') ?: (string) config('mail.default');

        if ($mailer === 'log') {
            return CheckResult::pass(__('Mail goes to the log'));
        }

        if ($mailer !== 'smtp') {
            return CheckResult::pass(__('Mail uses the :mailer mailer', ['mailer' => $mailer]));
        }

        $address = ($env->get('MAIL_HOST') ?: '127.0.0.1').':'.(int) $env->get('MAIL_PORT');
        $socket = @stream_socket_client('tcp://'.$address, $code, $error, 1.0);

        if ($socket === false) {
            return CheckResult::fail(__('Nothing accepts mail at :address', ['address' => $address]), __("Start Herd's mail service"));
        }

        fclose($socket);

        return CheckResult::pass(__('The mail server answers at :address', ['address' => $address]));
    }

    public function fix(DoctorContext $context): ?CheckResult
    {
        return null;
    }
}
