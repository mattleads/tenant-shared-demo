<?php

namespace App\MessageHandler;

use App\Message\NoteCreated;
use App\Repository\NoteRepository;
use App\Tenant\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class NoteCreatedHandler
{
    public function __construct(
        private readonly NoteRepository $notes,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(NoteCreated $message): void
    {
        // The repository reads from the database of TenantContext::current(): the worker set it from the stream.
        $note = $this->notes->find($message->noteId)
            ?? throw new \RuntimeException(sprintf('Note %d not found in tenant %s', $message->noteId, TenantContext::current()->uuid));

        $note->markProcessed(gethostname().':'.getmypid());
        $this->em->flush();
    }
}
