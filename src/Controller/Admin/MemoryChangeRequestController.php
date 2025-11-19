<?php

namespace App\Controller\Admin;

use App\Entity\MemoryChangeRequest;
use App\Entity\User;
use App\Repository\MemoryChangeRequestRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(path: '/admin/requests')]
#[Security("is_granted('ROLE_MANAGER') or is_granted('ROLE_ADMIN')")]
class MemoryChangeRequestController extends AbstractController
{
    public function __construct(
        private readonly MemoryChangeRequestRepository $requestRepository,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    #[Route(path: '/', name: 'admin_change_requests_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $userId = (int) $request->query->get('userId', 0);
        $selectedUser = $userId > 0 ? $this->userRepository->find($userId) : null;

        if ($selectedUser instanceof User) {
            $memories = $selectedUser->getMemories()->toArray();
            $requests = $this->requestRepository->findLatestForMemories($memories, 200);
        } else {
            $requests = $this->requestRepository->findLatestAll(200);
        }

        // Fallback include JSON files if DB table is missing or empty
        if (empty($requests)) {
            $dir = $this->getParameter('kernel.project_dir') . '/var/memory_change_requests';
            if (is_dir($dir)) {
                $files = glob($dir . '/*.json') ?: [];
                $requests = array_map(static function ($file) {
                    $data = json_decode(@file_get_contents($file), true) ?: [];
                    $data['createdAt'] = isset($data['createdAt']) ? new \DateTimeImmutable($data['createdAt']) : null;
                    return $data;
                }, $files);
                usort($requests, static function ($a, $b) {
                    return strcmp($b['createdAt']?->format('c') ?? '', $a['createdAt']?->format('c') ?? '');
                });
            }
        }

        return $this->render('admin/requests/index.html.twig', [
            'requests' => $requests,
            'users' => $this->userRepository->findBy([], ['email' => 'ASC']),
            'selectedUserId' => $selectedUser instanceof User ? $selectedUser->getId() : 0,
        ]);
    }

    #[Route(path: '/{id}/status/{status}', name: 'admin_change_request_status', methods: ['GET'])]
    public function updateStatus(MemoryChangeRequest $request, string $status): Response
    {
        $allowedStatuses = ['pending', 'reviewed', 'completed'];
        
        if (!in_array($status, $allowedStatuses, true)) {
            $this->addFlash('error', 'Недопустимый статус');
            return $this->redirectToRoute('admin_change_requests_index');
        }

        $request->setStatus($status);
        $this->entityManager->flush();

        $statusLabels = [
            'pending' => 'в ожидание',
            'reviewed' => 'просмотрено',
            'completed' => 'выполнено',
        ];

        $this->addFlash('success', sprintf('Статус заявки изменен на "%s"', $statusLabels[$status]));

        return $this->redirectToRoute('admin_change_requests_index');
    }
}


