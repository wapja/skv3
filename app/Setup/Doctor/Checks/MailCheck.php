<?php

declare(strict_types=1);

namespace App\Setup\Doctor\Checks;

use App\Setup\Doctor\Check;
use App\Setup\Doctor\CheckResult;
use App\Setup\Doctor\DoctorContext;

/**
 * §5.4, CD-5: with the smtp mailer, something accepts a TCP connection on the SMTP host and port within a second. A
 * runtime context (/health-check, the System page) reads the running configuration (mail.default and
 * mail.mailers.smtp.*); skv:doctor and setup read .env, the file they judge.
 */
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
        $mailer = $context->setting('MAIL_MAILER', 'mail.default') ?? (string) config('mail.default');

        if ($mailer === 'log') {
            return CheckResult::pass(__('Mail goes to the log'));
        }

        if ($mailer !== 'smtp') {
            return CheckResult::pass(__('Mail uses the :mailer mailer', ['mailer' => $mailer]));
        }

        $host = $context->setting('MAIL_HOST', 'mail.mailers.smtp.host') ?? '127.0.0.1';
        $address = $host.':'.(int) $context->setting('MAIL_PORT', 'mail.mailers.smtp.port');
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
