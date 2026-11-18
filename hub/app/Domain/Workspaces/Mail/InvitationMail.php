<?php

declare(strict_types=1);

namespace App\Domain\Workspaces\Mail;

use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Domain\Workspaces\Models\User;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class InvitationMail extends Mailable
{
    use Queueable;

    public function __construct(
        public Workspace $workspace,
        public User $inviter,
        public string $token,
        public WorkspaceRole $role,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __(':name te invitó a :workspace en Imagina Growth', [
            'name' => $this->inviter->name,
            'workspace' => $this->workspace->name,
        ]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.invitation', with: [
            'url' => rtrim((string) config('igr.urls.app'), '/').'/invitacion/'.$this->token,
        ]);
    }
}
