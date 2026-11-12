<?php

namespace App\Controller;

use App\Entity\Note;
use App\Message\NoteCreated;
use App\Message\SlowJob;
use App\Repository\NoteRepository;
use App\Tenant\TenantConnection;
use App\Tenant\TenantContext;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Nothing here knows about tenants. The routes have no prefix: `/tenant_<uuid>` is the request's base path, the
 * repository reads the tenant's database, the cache writes into the tenant's namespace, and the bus sends to the
 * tenant's stream.
 */
final class NotesController extends AbstractController
{
    #[Route('/health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return $this->json(['ok' => true]);
    }

    #[Route('/whoami', methods: ['GET'])]
    public function whoami(Connection $connection): JsonResponse
    {
        $tenant = TenantContext::current();

        return $this->json([
            'tenant' => $tenant->uuid,
            'database' => $connection->fetchOne('SELECT current_database()'),
            'basePath' => $this->container->get('request_stack')->getCurrentRequest()?->getBaseUrl(),
            'notesUrl' => $this->generateUrl('notes_list'),
            // Which process answered, how it runs, and what its database connection has done so far
            'pid' => getmypid(),
            'runtime' => $_SERVER['APP_RUNTIME_MODE'] ?? 'web=1',
            'connects' => TenantConnection::$connects,
            'open' => $connection instanceof TenantConnection ? $connection->open() : [],
        ]);
    }

    #[Route('/notes', name: 'notes_list', methods: ['GET'])]
    public function list(NoteRepository $notes): JsonResponse
    {
        return $this->json(array_map(fn (Note $n) => $n->toArray(), $notes->findBy([], ['id' => 'ASC'])));
    }

    #[Route('/notes', methods: ['POST'])]
    public function create(Request $request, EntityManagerInterface $em, MessageBusInterface $bus): JsonResponse
    {
        $body = (string) ($request->getPayload()->get('body') ?? '');
        if ($body === '') {
            return $this->json(['error' => 'body is required'], 422);
        }
        $note = new Note($body);
        $em->persist($note);
        $em->flush();

        $bus->dispatch(new NoteCreated($note->getId()));

        return $this->json($note->toArray(), 201);
    }

    /** Cached per tenant for 60 s: the pool is the same, the namespace is the tenant's. */
    #[Route('/notes/stats', methods: ['GET'])]
    public function stats(NoteRepository $notes, CacheInterface $cache): JsonResponse
    {
        $stats = $cache->get('notes_stats', function (ItemInterface $item) use ($notes): array {
            $item->expiresAfter(60);

            return ['count' => $notes->count([]), 'computedAt' => date(\DATE_ATOM), 'tenant' => TenantContext::current()->uuid];
        });

        return $this->json($stats);
    }

    #[Route('/jobs', methods: ['POST'])]
    public function job(Request $request, MessageBusInterface $bus): JsonResponse
    {
        $seconds = max(1, (int) ($request->getPayload()->get('seconds') ?? 2));
        $label = (string) ($request->getPayload()->get('label') ?? '');
        $bus->dispatch(new SlowJob($seconds, $label));

        return $this->json(['queued' => true, 'seconds' => $seconds, 'label' => $label], 202);
    }
}
