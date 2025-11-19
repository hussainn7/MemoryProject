<?php

namespace App\Controller\Front\Person;

use App\Entity\Client;
use App\Entity\Memory;
use App\Entity\MemoryChangeRequest;
use App\Entity\QrCode;
use App\Entity\User;

use App\Form\Front\Person\PersonCreateType;
use App\Form\Front\Person\PersonEditType;
use App\Notifier\CustomLoginLinkNotification;
use App\Repository\ClientRepository;
use App\Repository\QrCodeRepository;
use App\Repository\MemoryChangeRequestRepository;
use App\Repository\RoleRepository;
use App\Repository\StatusQrCodeRepository;
use App\Repository\UserRepository;
use App\Service\PersonService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Notifier\NotifierInterface;
use Symfony\Component\Notifier\Recipient\Recipient;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\LoginLink\LoginLinkHandlerInterface;
use Symfony\Component\Security\Http\LoginLink\LoginLinkNotification;
use Symfony\Contracts\Translation\TranslatorInterface;


#[Route(path: '/person')]
class PersonController extends AbstractController
{


    public function __construct(
        private readonly QrCodeRepository                        $qrCodeRepository,
        private readonly PersonService                           $personService,
        private readonly TranslatorInterface                     $translator,
        private readonly UserPasswordHasherInterface             $userPasswordEncoder,
        private readonly UserRepository                          $userRepository,
        private readonly RoleRepository                          $roleRepository,
        private readonly EntityManagerInterface                  $entityManager,
        private readonly StatusQrCodeRepository                  $statusQrCodeRepository,
        private readonly \Symfony\Bundle\SecurityBundle\Security $security,
        private readonly \Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface $params,
        private readonly \Psr\Log\LoggerInterface $logger  // ✅ ADD THIS LINE
    )
    {
    }


    #[Route(path: '/code/{uuid}', name: 'person_code', options: ['expose' => true], methods: ['GET', 'POST'])]
    public function indexCode(Request $request, string $uuid): Response
    {

        $qrCode = $this->qrCodeRepository->findOneBy(['uuid' => $uuid]);

        if ($qrCode !== null) {


            if ($qrCode->getStatus()->getName() === 'printed') {

                $memory = new Memory();

                $form = $this->createForm(PersonCreateType::class, $memory);

                $form->handleRequest($request);

                if ($form->isSubmitted() && !$form->isValid()) {
                    // Log form validation errors
                    $errors = [];
                    foreach ($form->getErrors(true) as $error) {
                        $errors[] = $error->getMessage();
                    }
                    $this->logger->error('Form validation failed', [
                        'errors' => $errors,
                        'formData' => $request->request->all(),
                    ]);
                    
                    // Return JSON response with errors for AJAX requests
                    if ($request->isXmlHttpRequest() || $request->headers->get('Content-Type') === 'application/json') {
                        return $this->json([
                            'success' => false,
                            'error' => 'Validation failed',
                            'errors' => $errors,
                            'message' => implode(', ', $errors)
                        ], Response::HTTP_BAD_REQUEST);
                    }
                }

                if ($form->isSubmitted() && $form->isValid()) {

                    $payload = $request->getPayload()->all();

                    $filesBag = $request->files->all();
                    $this->logger->info('Files received in request', [
                        'filesBagKeys' => array_keys($filesBag),
                        'filesBag' => $filesBag,
                        'requestMethod' => $request->getMethod(),
                        'contentType' => $request->headers->get('Content-Type'),
                    ]);
                    $uploadFiles = [];

                    // Handle specific file structure from JavaScript FormData
                    if (isset($filesBag['avatar']) && $filesBag['avatar'] instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
                        $avatarFile = $filesBag['avatar'];
                        if (!empty($avatarFile->getClientOriginalName()) && $avatarFile->getSize() > 0) {
                            $uploadFiles[] = $avatarFile;
                            $this->logger->info('Found avatar file', [
                                'name' => $avatarFile->getClientOriginalName(),
                                'size' => $avatarFile->getSize(),
                                'error' => $avatarFile->getError(),
                            ]);
                        } else {
                            $this->logger->warning('Invalid avatar file', [
                                'name' => $avatarFile->getClientOriginalName(),
                                'size' => $avatarFile->getSize(),
                                'error' => $avatarFile->getError(),
                            ]);
                        }
                    }

                    if (isset($filesBag['archive']) && is_array($filesBag['archive'])) {
                        foreach ($filesBag['archive'] as $archiveFile) {
                            if ($archiveFile instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
                                if (!empty($archiveFile->getClientOriginalName()) && $archiveFile->getSize() > 0) {
                                    $uploadFiles[] = $archiveFile;
                                    $this->logger->info('Found archive file', [
                                        'name' => $archiveFile->getClientOriginalName(),
                                        'size' => $archiveFile->getSize(),
                                        'error' => $archiveFile->getError(),
                                    ]);
                                } else {
                                    $this->logger->warning('Invalid archive file', [
                                        'name' => $archiveFile->getClientOriginalName(),
                                        'size' => $archiveFile->getSize(),
                                        'error' => $archiveFile->getError(),
                                    ]);
                                }
                            }
                        }
                    }

                    // Fallback to the old flatten method if needed
                    if (empty($uploadFiles)) {
                        $flatten = function ($value) use (&$uploadFiles, &$flatten) {
                            if ($value instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
                                $uploadFiles[] = $value;
                                return;
                            }
                            if (is_array($value)) {
                                foreach ($value as $v) {
                                    $flatten($v);
                                }
                            }
                        };
                        foreach ($filesBag as $item) {
                            $flatten($item);
                        }
                    }

                    $this->logger->info('Processed upload files', [
                        'uploadFilesCount' => count($uploadFiles),
                        'uploadFilesDetails' => array_map(function($file) {
                            return [
                                'name' => $file->getClientOriginalName(),
                                'size' => $file->getSize(),
                                'mimeType' => $file->getMimeType(),
                                'error' => $file->getError(),
                            ];
                        }, $uploadFiles),
                    ]);

                    $email = $payload['person_create']['emailClient']['first'];

                    //create Client and Set Client
                    $user = $this->userRepository->findOneBy(['email' => $email]);


                    if ($user === null) {

                        $user = new User();

                        $user->setEmail($email);

                        // Generate a unique username from email local-part
                        $emailParts = explode('@', $email);
                        $baseUsername = strtolower($emailParts[0] ?? 'user');
                        $candidateUsername = $baseUsername;
                        $attempts = 0;
                        while ($this->userRepository->findOneBy(['username' => $candidateUsername]) !== null && $attempts < 5) {
                            $candidateUsername = $baseUsername . '-' . substr(uniqid('', true), 0, 6);
                            $attempts++;
                        }
                        $user->setUsername($candidateUsername);

                        $role = $this->roleRepository->findOneBy(['name' => 'ROLE_MEMBER']);
                        $user->setRole($role);

                        $user->setPassword($this->userPasswordEncoder->hashPassword($user, Uuid::uuid4()));

                        $user->addQrCodeClient($qrCode);

                        $this->entityManager->persist($user);

                    }
                    $memory->setClient($user);

                    //set to QrCode Entity
                    $qrCode->setMemory($memory);

                    //set userQrCode to QrCode Entity
                    $user->addQrCodeClient($qrCode);

                    //change status to public
                    $qrCodeStatus = $this->statusQrCodeRepository->findOneBy(['name' => 'public']);

                    $qrCode->setStatus($qrCodeStatus);

                    //persist
                    $this->entityManager->persist($memory);


                    //set main_photo / photo archive based on uploadType meta
                    //create PhotoArhive and set PhotoArhive
                    $uploadType = $request->request->get('uploadType');
                    if ($uploadType === null) {
                        $uppyMeta = $request->request->get('uppyMeta');
                        if (is_string($uppyMeta)) {
                            $metaDecoded = json_decode($uppyMeta, true);
                            if (json_last_error() === JSON_ERROR_NONE) {
                                $uploadType = $metaDecoded['uploadType'] ?? null;
                            }
                        }
                    }

                    // If no uploadType but we have files, use auto mode
                    $mode = $uploadType === 'avatar' ? 'avatar' : ($uploadType === 'archive' ? 'archive' : 'auto');

                    $this->logger->info('Upload mode determined', [
                        'uploadType' => $uploadType,
                        'mode' => $mode,
                        'hasAvatarFile' => isset($filesBag['avatar']),
                        'archiveFileCount' => isset($filesBag['archive']) ? count($filesBag['archive']) : 0,
                    ]);

                    $uploadResult = $this->personService->uploadAttachments($memory, $uploadFiles, $mode);
                    if ($uploadResult instanceof JsonResponse) {
                        return $uploadResult;
                    }

                    //flush
                    $this->entityManager->flush();

                    // Handle photo extension payment if requested during creation
                    $wantPhotoExtension = $request->request->get('wantPhotoExtension');
                    if ($wantPhotoExtension === '1') {
                        $price = (float) $this->params->get('photo_extension_price', 500);
                        
                        // Check if user has sufficient balance
                        if ($user->getBalance() >= $price) {
                            // Deduct balance
                            $paymentService = $this->container->get(\App\Service\Payment\PaymentService::class);
                            $result = $paymentService->simulateRobokassaDeposit($user, -$price);
                            
                            if ($result->isSuccess()) {
                                // Activate extension
                                $memory->setIsExtended(true);
                                $this->entityManager->flush();
                                
                                $this->logger->info('Photo extension activated during creation', [
                                    'memoryId' => $memory->getId(),
                                    'userId' => $user->getId(),
                                    'price' => $price,
                                ]);
                            }
                        } else {
                            $this->logger->warning('Insufficient balance for photo extension', [
                                'memoryId' => $memory->getId(),
                                'userId' => $user->getId(),
                                'balance' => $user->getBalance(),
                                'required' => $price,
                            ]);
                        }
                    }

                    return new JsonResponse(['success' => true]);

                }
                return $this->render('frontend/person/index.html.twig', [
                    'form' => $form->createView(),
                    'uuid' => $uuid,
                ]);


            } elseif ($qrCode->getStatus()->getName() === 'public') {
                $memory = $qrCode->getMemory();

                // Handle additional file uploads posted after memory is public (e.g., archive in second step)
                if ($request->isMethod('POST')) {
                    $filesBag = $request->files->all();
                    $uploadFiles = [];
                    $flatten = function ($value) use (&$uploadFiles, &$flatten) {
                        if ($value instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
                            $uploadFiles[] = $value;
                            return;
                        }
                        if (is_array($value)) {
                            foreach ($value as $v) {
                                $flatten($v);
                            }
                        }
                    };
                    foreach ($filesBag as $item) {
                        $flatten($item);
                    }

                    if (count($uploadFiles) > 0) {
                        // determine mode - check multiple sources for uploadType
                        $uploadType = $request->request->get('uploadType');
                        
                        // Log all request data for debugging
                        $this->logger->info('Upload request received', [
                            'uploadType_direct' => $uploadType,
                            'request_data' => $request->request->all(),
                            'has_files' => $request->files->has('files'),
                            'fileCount' => count($uploadFiles),
                            'memoryId' => $memory->getId(),
                        ]);
                        
                        if ($uploadType === null || $uploadType === '') {
                            // Try uppyMeta JSON
                            $uppyMeta = $request->request->get('uppyMeta');
                            if (is_string($uppyMeta)) {
                                $metaDecoded = json_decode($uppyMeta, true);
                                if (json_last_error() === JSON_ERROR_NONE) {
                                    $uploadType = $metaDecoded['uploadType'] ?? null;
                                    $this->logger->info('Found uploadType in uppyMeta', ['uploadType' => $uploadType]);
                                }
                            }
                            // Try from files metadata (if sent as form field)
                            if (($uploadType === null || $uploadType === '') && $request->files->has('files')) {
                                $filesMeta = $request->request->get('files');
                                if (is_array($filesMeta) && isset($filesMeta['uploadType'])) {
                                    $uploadType = $filesMeta['uploadType'];
                                    $this->logger->info('Found uploadType in files meta', ['uploadType' => $uploadType]);
                                }
                            }
                            // If still not found, default to 'archive' if main photo exists (for public pages)
                            if (($uploadType === null || $uploadType === '') && $memory->getMainPhoto() !== null) {
                                $uploadType = 'archive';
                                $this->logger->info('Defaulting to archive mode (main photo exists)');
                            }
                        }
                        
                        // Force 'archive' mode if we're on a public page and main photo exists
                        // This ensures limit check always runs for archive uploads
                        if ($memory->getMainPhoto() !== null && $uploadType !== 'avatar') {
                            $uploadType = 'archive';
                        }
                        
                        $mode = $uploadType === 'avatar' ? 'avatar' : ($uploadType === 'archive' ? 'archive' : 'auto');
                        
                        // ✅ ADD THIS DEBUG LOGGING
                        $currentArchiveCount = 0;
                        if ($memory->getMainPhoto() !== null) {
                            $memory->getPhotoArhive()->count();
                            $mainPhoto = $memory->getMainPhoto();
                            foreach ($memory->getPhotoArhive() as $photo) {
                                $photoPath = $photo->getPhoto();
                                if ($photoPath !== null && $photoPath !== '' && $photoPath !== $mainPhoto) {
                                    $currentArchiveCount++;
                                }
                            }
                        }
                        
                        $this->logger->info('🔍 UPLOAD ATTEMPT', [
                            'mode' => $mode,
                            'uploadType' => $uploadType,
                            'fileCount' => count($uploadFiles),
                            'currentArchiveCount' => $currentArchiveCount,
                            'isExtended' => $memory->isExtended(),
                            'hasMainPhoto' => $memory->getMainPhoto() !== null,
                            'memoryId' => $memory->getId(),
                        ]);

                        $uploadResult = $this->personService->uploadAttachments($memory, $uploadFiles, $mode);
                        if ($uploadResult instanceof JsonResponse) {
                            // ✅ ADD THIS DEBUG LOGGING
                            $this->logger->warning('🚫 UPLOAD BLOCKED', [
                                'status' => $uploadResult->getStatusCode(),
                                'content' => $uploadResult->getContent(),
                                'memoryId' => $memory->getId(),
                            ]);
                            return $uploadResult;
                        }
                        $this->entityManager->flush();
                        return new JsonResponse(['success' => true]);
                    }
                }

                // Initialize photo archive collection to ensure availability in the view
                $memory->getPhotoArhive()->count();
                
                // Initialize burial place collection to ensure availability in the view
                $memory->getBurialPlace()->count();

                return $this->render('frontend/person/data.html.twig', [
                    'memory' => $memory,
                    'uuid' => $uuid,
                    'photo_extension_price' => (int) $this->params->get('photo_extension_price', 500),
                ]);
            } else {
                return $this->render('frontend/error/index.html.twig');
            }


            // return $this->render('frontend/person/index.html.twig');


        } else {
            return $this->render('frontend/error/index.html.twig');
        }


    }


    #[Route('/get-edit-person-link/{uuid}', name: 'person_code_edit_link', options: ['expose' => true], methods: ['GET', 'POST'])]
    public function requestLoginLink(NotifierInterface $notifier, LoginLinkHandlerInterface $loginLinkHandler, UserRepository $userRepository, Request $request, string $uuid): JsonResponse
    {

        if ($request->isMethod('POST')) {

            $qrCode = $this->qrCodeRepository->findOneBy(['uuid' => $uuid]);

            if ($qrCode === null) {
                return new JsonResponse(['success' => false]);
            }


            $email = $qrCode->getClient()->getEmail();

            $user = $userRepository->findOneBy(['email' => $email]);

            $loginLinkDetails = $loginLinkHandler->createLoginLink($user);

            // create a notification based on the login link details
            $notification = new CustomLoginLinkNotification(
                $loginLinkDetails,
                $this->translator->trans('person.mail.link_edit') // email subject
            );


            // create a recipient for this user
            $recipient = new Recipient($user->getEmail());

            // send the notification to the user
            $notifier->send($notification, $recipient);


        }

        return new JsonResponse(['success' => true]);

    }

//    #[Security("is_granted('ROLE_MEMBER')")]
    #[Route(path: '/edit', name: 'edit_person_list', methods: ['GET', 'POST'])]
    public function editList(): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        dump($currentUser);

        if ($currentUser === null) {
            return $this->render('frontend/error/index.html.twig');
        }

        // Check if user is admin/manager - they see all QR codes they created
        $isAdmin = $this->security->isGranted('ROLE_MANAGER') || $this->security->isGranted('ROLE_ADMIN');
        
        if ($isAdmin) {
            // For admins: show QR codes they created (as user/creator)
            $qrCodes = $currentUser->getQrCodes();
        } else {
            // For simple users: show QR codes where they are the client (owner of the memory)
            $qrCodes = $currentUser->getQrCodeClient();
        }

        //dump($qrCodes[0]);

        return $this->render('frontend/person/list_data.html.twig', [
            'qrCodes' => $qrCodes
        ]);

    }

    #[Route(path: '/edit/code/{uuid}', name: 'edit_person_page', options: ['expose' => true], methods: ['GET', 'POST'])]
    public function indexPage(Request $request, string $uuid): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();

        if ($currentUser === null) {
            $this->logger->warning('Edit page: User not logged in');
            return $this->render('frontend/error/index.html.twig');
        }

        $qrCode = $this->qrCodeRepository->findOneBy(['uuid' => $uuid]);

        if ($qrCode === null) {
            $this->logger->error('Edit page: QR code not found', ['uuid' => $uuid]);
            return $this->render('frontend/error/index.html.twig');
        }

        $clientQrCodes = $currentUser->getQrCodeClient()->toArray();

        // Check if user is admin or manager - they can edit any page
        $isAdmin = in_array('ROLE_ADMIN', $currentUser->getRoles(), true) 
                   || in_array('ROLE_MANAGER', $currentUser->getRoles(), true);

        $this->logger->info('Edit page access check', [
            'uuid' => $uuid,
            'userEmail' => $currentUser->getEmail(),
            'userRoles' => $currentUser->getRoles(),
            'isAdmin' => $isAdmin,
            'isOwner' => in_array($qrCode, $clientQrCodes, true),
        ]);

        //check if user have access to this data (owner or admin)
        if (in_array($qrCode, $clientQrCodes, true) || $isAdmin) {

            $memory = $qrCode->getMemory();


            $originalWordsMemory = new ArrayCollection();

//            // Create an ArrayCollection of the current Tag objects in the database
//            foreach ($memory->getWordsMemory() as $words) {
//                $originalWordsMemory->add($words);
//            }


            $form = $this->createForm(PersonEditType::class, $memory);

            $form->handleRequest($request);

            if ($form->isSubmitted() && $form->isValid()) {


                $allWords = $memory->getWordsMemory();

                $allLinks = $memory->getLinksMemory();

                $allEpitaph = $memory->getEpitaph();

                $allBurialPlace = $memory->getBurialPlace();

                foreach ($allWords as $word) {
                    if ($word->getWords() === null) {
                        $this->entityManager->remove($word);
                    }
                }

                foreach ($allLinks as $link) {
                    if ($link->getLink() === null) {
                        $this->entityManager->remove($link);
                    }
                }

                foreach ($allEpitaph as $epitaph) {
                    if ($epitaph->getText() === null) {
                        $this->entityManager->remove($epitaph);
                    }
                }

                foreach ($allBurialPlace as $burialPlace) {
                    if ($burialPlace->getLat() === null) {
                        $this->entityManager->remove($burialPlace);
                    }
                }

                //change status to public
                $qrCodeStatus = $this->statusQrCodeRepository->findOneBy(['name' => 'public']);

                $qrCode->setStatus($qrCodeStatus);


                $this->personService->deleteAttachment($memory);

                $filesBag = $request->files->all();
                $uploadFiles = [];
                $flatten = function ($value) use (&$uploadFiles, &$flatten) {
                    if ($value instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
                        $uploadFiles[] = $value;
                        return;
                    }
                    if (is_array($value)) {
                        foreach ($value as $v) {
                            $flatten($v);
                        }
                    }
                };
                foreach ($filesBag as $item) {
                    $flatten($item);
                }

                // determine mode for edit uploads
                $uploadType = $request->request->get('uploadType');
                if ($uploadType === null) {
                    $uppyMeta = $request->request->get('uppyMeta');
                    if (is_string($uppyMeta)) {
                        $metaDecoded = json_decode($uppyMeta, true);
                        if (json_last_error() === JSON_ERROR_NONE) {
                            $uploadType = $metaDecoded['uploadType'] ?? null;
                        }
                    }
                }
                $mode = $uploadType === 'avatar' ? 'avatar' : ($uploadType === 'archive' ? 'archive' : 'auto');

                $uploadResult = $this->personService->uploadAttachments($memory, $uploadFiles, $mode);
                if ($uploadResult instanceof JsonResponse) {
                    return $uploadResult;
                }

                $this->entityManager->flush();

            }

            return $this->render('frontend/person/edit.html.twig', [
                'qrCode' => $qrCode,
                'form' => $form->createView(),
                'uuid' => $uuid
            ]);


        }

        // Access denied
        $this->logger->warning('Edit page: Access denied', [
            'uuid' => $uuid,
            'userEmail' => $currentUser->getEmail(),
            'userRoles' => $currentUser->getRoles(),
        ]);

        return $this->render('frontend/error/index.html.twig');

    }


    #[Route(path: '/get-preview-main-photo/{uuid}', name: 'person_get_preview_main_photo', options: ['expose' => true], methods: ['GET', 'POST'])]
    public function getPreviewFilesMainPhoto(Request $request, string $uuid): Response
    {

        /** @var User $currentUser */
        $currentUser = $this->getUser();

        if ($currentUser === null) {
            return $this->render('frontend/error/index.html.twig');
        }

        $qrCode = $this->qrCodeRepository->findOneBy(['uuid' => $uuid]);

        if ($qrCode !== null) {

            $memory = $qrCode->getMemory();

            $clientQrCodes = $currentUser->getQrCodeClient()->toArray();

            //check if user have access to this data
            if (in_array($qrCode, $clientQrCodes)) {

                $mainPhoto = $memory->getMainPhoto();
                $previewFiles = [];

                if ($mainPhoto) {
                    $previewFiles = $this->personService->getMainPhotoForEdit($mainPhoto);
                }

                return $this->json($previewFiles, Response::HTTP_OK);

            }
        }

        return $this->render('frontend/error/index.html.twig');

    }

    #[Route(path: '/get-preview-photo-arhive/{uuid}', name: 'person_get_preview_photo_arhive', options: ['expose' => true], methods: ['GET', 'POST'])]
    public function getPreviewFilesPhotoArhive(Request $request, string $uuid): Response
    {

        /** @var User $currentUser */
        $currentUser = $this->getUser();

        if ($currentUser === null) {
            return $this->render('frontend/error/index.html.twig');
        }

        $qrCode = $this->qrCodeRepository->findOneBy(['uuid' => $uuid]);

        if ($qrCode !== null) {

            $memory = $qrCode->getMemory();

            $clientQrCodes = $currentUser->getQrCodeClient()->toArray();

            //check if user have access to this data
            if (in_array($qrCode, $clientQrCodes)) {

                $photoArhives = $memory->getPhotoArhive();
                $previewFiles = [];

                if ($photoArhives) {
                    $previewFiles = $this->personService->getPhotoArhiveForEdit($photoArhives);
                }

                return $this->json($previewFiles, Response::HTTP_OK);

            }
        }

        return $this->render('frontend/error/index.html.twig');

    }



    #[Route(path: '/privacy', name: 'person_privacy', options: ['expose' => true], methods: ['GET', 'POST'])]
    public function privacy(Request $request): Response
    {
        return $this->render('frontend/person/_modal_privacy.html.twig');
    }

    #[Route(path: '/code/{uuid}/submit-change-request', name: 'person_submit_change_request', options: ['expose' => true], methods: ['POST'])]
    public function submitChangeRequest(Request $request, string $uuid): Response
    {
        $qrCode = $this->qrCodeRepository->findOneBy(['uuid' => $uuid]);
        if ($qrCode === null) {
            return $this->json(['success' => false, 'message' => 'Страница не найдена'], Response::HTTP_NOT_FOUND);
        }

        $memory = $qrCode->getMemory();
        if ($memory === null) {
            return $this->json(['success' => false, 'message' => 'Память не найдена'], Response::HTTP_NOT_FOUND);
        }

        $name = trim((string) $request->request->get('name'));
        $email = trim((string) $request->request->get('email'));
        $message = trim((string) $request->request->get('message'));

        if ($name === '' || $email === '') {
            return $this->json(['success' => false, 'message' => 'Заполните имя и e-mail'], Response::HTTP_BAD_REQUEST);
        }

        $changeRequest = new MemoryChangeRequest();
        $changeRequest->setMemory($memory)
            ->setRequesterName($name)
            ->setRequesterEmail($email)
            ->setMessage($message)
            ->setStatus('pending');

        // handle optional files (photos[])
        $attachments = [];
        $files = $request->files->get('photos', []);
        $targetDir = $this->params->get('files_requests_directory');
        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0775, true);
        }
        if (is_array($files)) {
            foreach ($files as $file) {
                if ($file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
                    $ext = pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION) ?: 'jpg';
                    $save = uniqid('mcr_') . '.' . $ext;
                    try {
                        $file->move($targetDir, $save);
                        $attachments[] = '/img/requests/' . $save;
                    } catch (\Throwable) {
                        // ignore failed attachment
                    }
                }
            }
        }
        if (!empty($attachments)) {
            $changeRequest->setAttachments($attachments);
        }

        try {
            $this->entityManager->persist($changeRequest);
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            // Fallback storage when DB table is missing
            try {
                $baseDir = $this->getParameter('kernel.project_dir') . '/var/memory_change_requests';
                if (!is_dir($baseDir)) {
                    @mkdir($baseDir, 0775, true);
                }
                $payload = [
                    'uuid' => $uuid,
                    'memoryId' => $memory->getId(),
                    'requesterName' => $name,
                    'requesterEmail' => $email,
                    'message' => $message,
                    'attachments' => $attachments,
                    'createdAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
                ];
                $file = $baseDir . '/' . uniqid('mcr_', true) . '.json';
                @file_put_contents($file, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            } catch (\Throwable) {
                // Ignore FS errors too
            }
        }

        // No emails for now — platform-only workflow

        return $this->json(['success' => true]);
    }

    #[Route(path: '/edit/requests', name: 'person_change_requests', methods: ['GET'])]
    public function ownerRequests(\App\Repository\MemoryChangeRequestRepository $requestRepository): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        if ($currentUser === null) {
            return $this->render('frontend/error/index.html.twig');
        }

        // Only show requests for memories owned by this user
        $memories = $currentUser->getMemories()->toArray();
        if (empty($memories)) {
            return $this->render('frontend/person/change_requests.html.twig', [
                'requests' => [],
            ]);
        }
        $requests = $requestRepository->findLatestForMemories($memories, 200);

        // Fallback to JSON files if DB is missing - filter by user's memories
        if (empty($requests)) {
            $dir = $this->getParameter('kernel.project_dir') . '/var/memory_change_requests';
            if (is_dir($dir)) {
                $userMemoryUuids = array_map(static function ($m) {
                    return $m->getStatus()?->getUuid();
                }, $memories);
                $userMemoryIds = array_map(static function ($m) {
                    return $m->getId();
                }, $memories);
                
                $files = glob($dir . '/*.json') ?: [];
                $filteredRequests = [];
                foreach ($files as $file) {
                    $data = json_decode(@file_get_contents($file), true) ?: [];
                    // Filter: only include if UUID matches or memoryId matches user's memories
                    if (isset($data['uuid']) && in_array($data['uuid'], $userMemoryUuids, true)) {
                        $data['createdAt'] = isset($data['createdAt']) ? new \DateTimeImmutable($data['createdAt']) : null;
                        $filteredRequests[] = $data;
                    } elseif (isset($data['memoryId']) && in_array($data['memoryId'], $userMemoryIds, true)) {
                        $data['createdAt'] = isset($data['createdAt']) ? new \DateTimeImmutable($data['createdAt']) : null;
                        $filteredRequests[] = $data;
                    }
                }
                usort($filteredRequests, static function ($a, $b) {
                    return strcmp($b['createdAt']?->format('c') ?? '', $a['createdAt']?->format('c') ?? '');
                });
                $requests = array_slice($filteredRequests, 0, 200);
            }
        }

        return $this->render('frontend/person/change_requests.html.twig', [
            'requests' => $requests,
        ]);
    }

    #[Route(path: '/check-upload-limit/{uuid}', name: 'person_check_upload_limit', options: ['expose' => true], methods: ['GET'])]
    public function checkUploadLimit(string $uuid): JsonResponse
    {
        $qrCode = $this->qrCodeRepository->findOneBy(['uuid' => $uuid]);
        
        if (!$qrCode || !$qrCode->getMemory()) {
            return new JsonResponse([
                'canUpload' => true,
                'currentCount' => 0,
                'isExtended' => false,
                'freeLimit' => 5,
                'price' => (int) $this->params->get('photo_extension_price', 500),
            ]);
        }
        
        $memory = $qrCode->getMemory();
        
        // Force load collection and count archive photos (excluding main photo)
        $memory->getPhotoArhive()->count();
        $mainPhoto = $memory->getMainPhoto();
        $currentArchiveCount = 0;
        
        foreach ($memory->getPhotoArhive() as $photo) {
            $photoPath = $photo->getPhoto();
            if ($photoPath !== null && $photoPath !== '' && $photoPath !== $mainPhoto) {
                $currentArchiveCount++;
            }
        }
        
        $isExtended = $memory->isExtended();
        $freeLimit = 5;
        $price = (int) $this->params->get('photo_extension_price', 500);
        
        $canUpload = $isExtended || $currentArchiveCount < $freeLimit;
        
        return new JsonResponse([
            'canUpload' => $canUpload,
            'currentCount' => $currentArchiveCount,
            'isExtended' => $isExtended,
            'freeLimit' => $freeLimit,
            'remaining' => max(0, $freeLimit - $currentArchiveCount),
            'price' => $price,
        ]);
    }

    #[Route(path: '/code/{uuid}/extend-photos', name: 'person_extend_photos', options: ['expose' => true], methods: ['POST'])]
    #[Route(path: '/code/{uuid}/upgrade-photo-limit', name: 'person_upgrade_photo_limit', options: ['expose' => true], methods: ['POST'])]
    public function extendPhotos(Request $request, string $uuid, \App\Service\Payment\PaymentService $paymentService): Response
    {
        $qrCode = $this->qrCodeRepository->findOneBy(['uuid' => $uuid]);
        if ($qrCode === null) {
            return $this->json(['success' => false, 'message' => 'Страница не найдена'], Response::HTTP_NOT_FOUND);
        }

        $memory = $qrCode->getMemory();
        if ($memory === null) {
            return $this->json(['success' => false, 'message' => 'Память не найдена'], Response::HTTP_NOT_FOUND);
        }

        // Get the client who owns this memory
        $memoryOwner = $memory->getClient();
        if ($memoryOwner === null) {
            return $this->json(['success' => false, 'message' => 'Владелец не найден'], Response::HTTP_NOT_FOUND);
        }

        // Check if already extended
        if ($memory->isExtended()) {
            return $this->json([
                'success' => true,
                'message' => 'Расширенный доступ уже активирован',
            ]);
        }

        // Check balance and charge
        $price = (float) $this->params->get('photo_extension_price', 500);
        if ($memoryOwner->getBalance() < $price) {
            return $this->json([
                'success' => false,
                'message' => sprintf('Недостаточно средств. Требуется %d ₽, доступно %.2f ₽', $price, $memoryOwner->getBalance()),
            ], 402); // Payment Required
        }

        // Deduct balance and activate extension
        $result = $paymentService->simulateRobokassaDeposit($memoryOwner, -$price);
        if (!$result->isSuccess()) {
            return $this->json(['success' => false, 'message' => 'Ошибка списания средств'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $memory->setIsExtended(true);
        $this->entityManager->flush();

        return $this->json([
            'success' => true,
            'message' => 'Расширенный доступ активирован',
            'balance' => $memoryOwner->getBalance(),
        ]);
    }

    /**
     * Get current archive count for proactive blocking
     */
    #[Route(path: '/get-archive-count/{uuid}', name: 'person_get_archive_count', options: ['expose' => true], methods: ['GET'])]
    public function getArchiveCount(string $uuid): JsonResponse
    {
        $qrCode = $this->qrCodeRepository->findOneBy(['uuid' => $uuid]);
        
        if ($qrCode === null) {
            return $this->json([
                'success' => false,
                'message' => 'QR code not found'
            ], Response::HTTP_NOT_FOUND);
        }
        
        $memory = $qrCode->getMemory();
        
        if ($memory === null) {
            return $this->json([
                'success' => false,
                'currentCount' => 0,
                'isExtended' => false,
                'price' => (int) $this->params->get('photo_extension_price', 500),
            ]);
        }
        
        // Count archive photos (excluding main photo)
        $memory->getPhotoArhive()->count();
        $mainPhoto = $memory->getMainPhoto();
        $currentArchiveCount = 0;
        foreach ($memory->getPhotoArhive() as $photo) {
            $photoPath = $photo->getPhoto();
            if ($photoPath !== null && $photoPath !== '' && $photoPath !== $mainPhoto) {
                $currentArchiveCount++;
            }
        }
        
        return $this->json([
            'success' => true,
            'currentCount' => $currentArchiveCount,
            'isExtended' => $memory->isExtended(),
            'freeLimit' => 5,
            'price' => (int) $this->params->get('photo_extension_price', 500),
        ]);
    }

    /**
     * Test endpoint to verify limit check state
     */
    #[Route(path: '/test-limit/{uuid}', name: 'person_test_limit', methods: ['GET'])]
    public function testLimit(string $uuid): JsonResponse
    {
        $qrCode = $this->qrCodeRepository->findOneBy(['uuid' => $uuid]);
        if ($qrCode === null) {
            return $this->json(['error' => 'QR Code not found'], Response::HTTP_NOT_FOUND);
        }
        
        $memory = $qrCode->getMemory();
        if ($memory === null) {
            return $this->json(['error' => 'Memory not found'], Response::HTTP_NOT_FOUND);
        }
        
        // Count archive photos (excluding main photo)
        $memory->getPhotoArhive()->count();
        $mainPhoto = $memory->getMainPhoto();
        $currentArchiveCount = 0;
        foreach ($memory->getPhotoArhive() as $photo) {
            $photoPath = $photo->getPhoto();
            if ($photoPath !== null && $photoPath !== '' && $photoPath !== $mainPhoto) {
                $currentArchiveCount++;
            }
        }
        
        $freeLimit = 5;
        $isExtended = $memory->isExtended();
        $wouldBlock = !$isExtended && $currentArchiveCount >= $freeLimit;
        
        return $this->json([
            'currentCount' => $currentArchiveCount,
            'freeLimit' => $freeLimit,
            'isExtended' => $isExtended,
            'mainPhoto' => $memory->getMainPhoto() !== null,
            'wouldBlock' => $wouldBlock,
            'canUploadMore' => !$wouldBlock,
            'price' => (int) $this->params->get('photo_extension_price', 500),
        ]);
    }

}
