<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/** A mail transport whose server is always down. */
final class FailingMailTransport implements TransportInterface
{
    public function __toString(): string
    {
        return 'failing';
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        throw new TransportException('The mail server is down.');
    }
}
