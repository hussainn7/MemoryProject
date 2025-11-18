<?php

namespace App\Controller\Admin\Dashboard;

use App\Entity\User;
use App\Repository\MemoryChangeRequestRepository;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;


#[Route(path: '/admin/dashboard')]
#[Security("is_granted('ROLE_MANAGER')")]
class DashboardController extends AbstractController
{


    public function __construct(
        private readonly MemoryChangeRequestRepository $requestRepository
    ) {
    }


    #[Route(path: '/', name: 'admin_dashboard')]
    public function indexDashoard()
    {
        /** @var User $user */
        $user = $this->getUser();

        // Only admins can see change requests
        $requests = [];
        $isAdmin = $user && (in_array('ROLE_ADMIN', $user->getRoles(), true) || in_array('ADMIN', $user->getRoles(), true));
        
        if ($isAdmin) {
            $requests = $this->requestRepository->findLatestAll(10);

            // Fallback to JSON files if DB table is missing
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
                    $requests = array_slice($requests, 0, 10);
                }
            }
        }

        return $this->render('admin/dashboard/index.html.twig', [
            'changeRequests' => $requests,
            'isAdmin' => $isAdmin,
        ]);
    }


//    #[Route(path: '/', name: 'admin')]
//    #[Route(path: '/', name: 'admin_index')]
//    public function index()
//    {
//        return $this->redirectToRoute('security_login');
//    }
}
