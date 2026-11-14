<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Builds the invitation email inline so the project does not need Twig.
 * Delivery failures are logged and reported to the caller, not thrown.
 */
final class BoardInvitationMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(APP_FRONTEND_URL)%')]
        private readonly string $frontendUrl,
    ) {
    }

    public function send(string $recipient, string $boardTitle, string $inviterName, string $role, ?string $invitationToken = null): bool
    {
        $base = rtrim($this->frontendUrl, '/');
        $link = null !== $invitationToken
            ? $base.'/sign-in?invite='.rawurlencode($invitationToken)
            : $base.'/sign-in';

        $title = htmlspecialchars($boardTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $inviter = htmlspecialchars($inviterName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $roleLabel = htmlspecialchars($role, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $href = htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $html = <<<HTML
            <div style="font-family:Inter,Segoe UI,sans-serif;background:#F6F5FB;padding:32px">
              <div style="max-width:520px;margin:0 auto;background:#ffffff;border:1px solid #E7E4F2;border-radius:16px;padding:28px">
                <p style="margin:0 0 8px;color:#6D4AFF;font-weight:700;letter-spacing:.02em">MOSAIQ</p>
                <h1 style="margin:0 0 12px;font-size:22px;color:#1D1A2B">You are invited to “{$title}”</h1>
                <p style="margin:0 0 8px;color:#5D5870;font-size:14px">{$inviter} invited you to collaborate with the role <strong>{$roleLabel}</strong>.</p>
                <p style="margin:24px 0"><a href="{$href}" style="display:inline-block;background:#6D4AFF;color:#ffffff;text-decoration:none;padding:12px 18px;border-radius:10px;font-weight:600">Open Mosaiq</a></p>
                <p style="margin:0;color:#807A94;font-size:12px">This link expires in 7 days. If you did not expect this invitation, you can ignore this email.</p>
              </div>
            </div>
            HTML;

        $text = "{$inviter} invited you to the Mosaiq board \"{$boardTitle}\" with the {$role} role.\n\nOpen: {$link}\n\nThis link expires in 7 days.";

        try {
            $this->mailer->send(
                (new Email())
                    ->from('Mosaiq <invitations@mosaiq.tn>')
                    ->to($recipient)
                    ->subject(sprintf('Mosaiq invitation · %s', $boardTitle))
                    ->html($html)
                    ->text($text),
            );
            return true;
        } catch (\Throwable $exception) {
            $this->logger->warning('Board invitation email could not be sent.', [
                'recipient' => $recipient,
                'board' => $boardTitle,
                'error' => $exception->getMessage(),
            ]);
            return false;
        }
    }
}
