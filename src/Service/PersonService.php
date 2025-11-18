<?php

namespace App\Service;


use App\Entity\Memory;
use App\Entity\PhotoArhive;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\String\Slugger\SluggerInterface;

class PersonService
{
    public function __construct(
        private readonly ParameterBagInterface  $params,
        private readonly EntityManagerInterface $entityManager,
        private readonly SluggerInterface       $slugger,
        private readonly LoggerInterface        $logger,
    )
    {
    }

    public function uploadAttachments(Memory $memory, array $uploadFiles, string $mode = 'auto'): bool|JsonResponse
    {
        $totalFiles = is_countable($uploadFiles) ? count($uploadFiles) : 0;
        $archiveCount = 0;
        $mainSet = false;
        $avatarFound = false;
        $firstSavedFileName = null;
        $firstArchiveEntity = null;

        $this->logger->info('Upload attachments called', [
            'memoryId' => $memory->getId(),
            'mode' => $mode,
            'fileCount' => $totalFiles,
            'hasMainPhoto' => $memory->getMainPhoto() !== null,
        ]);


        // Helper for saving a single file and returning the stored name
        $saveUploadedFile = function (UploadedFile $uploadUserFile): ?string {
            $originalFilename = pathinfo($uploadUserFile->getClientOriginalName(), PATHINFO_FILENAME);
            // Get extension from original filename
            $extension = strtolower(pathinfo($uploadUserFile->getClientOriginalName(), PATHINFO_EXTENSION));

            // Fallback to mime type if extension is empty
            if (empty($extension)) {
                $mimeType = $uploadUserFile->getMimeType();
                $extension = match ($mimeType) {
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/gif' => 'gif',
                    'image/webp' => 'webp',
                    default => 'jpg', // fallback
                };
            }

            // Use a simple filename without slugger to avoid issues
            $safeFilename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $originalFilename);

            if (empty($extension)) {
                $this->logger->error('File extension is empty!', [
                    'originalName' => $uploadUserFile->getClientOriginalName(),
                    'mimeType' => $uploadUserFile->getMimeType(),
                    'originalFilename' => $originalFilename,
                ]);
                return null;
            }

            $saveFileName = uniqid() . '_' . $safeFilename . '.' . $extension;
            $targetDirectory = $this->params->get('files_directory');

            if (empty($targetDirectory)) {
                $this->logger->error('Target directory is empty!');
                return null;
            }

            if (empty($saveFileName)) {
                $this->logger->error('Save filename is empty!');
                return null;
            }

            $this->logger->info('File save details', [
                'originalName' => $uploadUserFile->getClientOriginalName(),
                'extension' => $extension,
                'safeFilename' => $safeFilename,
                'saveFileName' => $saveFileName,
                'targetDirectory' => $targetDirectory,
                'fullPath' => $targetDirectory . '/' . $saveFileName,
            ]);

            try {
                $uploadUserFile->move(
                    $targetDirectory,
                    $saveFileName
                );

                $this->logger->info('File saved successfully', [
                    'path' => $targetDirectory . '/' . $saveFileName,
                ]);

            } catch (FileException $e) {
                $this->logger->error('Unable to move uploaded memory file.', [
                    'exception' => $e->getMessage(),
                    'originalName' => $uploadUserFile->getClientOriginalName(),
                    'extension' => $extension,
                    'saveFileName' => $saveFileName,
                    'targetDirectory' => $targetDirectory,
                    'fileReadable' => is_readable($uploadUserFile->getPathname()),
                    'directoryWritable' => is_writable($targetDirectory),
                ]);
                return null;
            }
            return $saveFileName;
        };

        // Strict modes: 'avatar' or 'archive'
        if ($mode === 'avatar') {
            foreach ($uploadFiles as $uploadUserFile) {
                if (!$uploadUserFile instanceof UploadedFile) {
                    continue;
                }
                $stored = $saveUploadedFile($uploadUserFile);
                if ($stored === null) {
                    return new JsonResponse([
                        'success' => false,
                        'message' => 'Не удалось сохранить файл. Попробуйте снова.',
                    ], Response::HTTP_INTERNAL_SERVER_ERROR);
                }
                $firstSavedFileName = $stored;
                break; // Only first file counts for avatar
            }

            if ($firstSavedFileName !== null) {
                $memory->setMainPhoto($firstSavedFileName);
                $mainSet = true;
                $avatarFound = true;
                // Remove any archive entries that accidentally match main
                foreach ($memory->getPhotoArhive() as $existing) {
                    if ($existing->getPhoto() === $firstSavedFileName) {
                        $memory->removePhotoArhive($existing);
                        $this->entityManager->remove($existing);
                    }
                }
            }

        } elseif ($mode === 'archive' || ($mode === 'auto' && $memory->getMainPhoto() !== null)) {
            // Limit check already done above if main photo exists

            foreach ($uploadFiles as $uploadUserFile) {
                if (!$uploadUserFile instanceof UploadedFile) {
                    continue;
                }
                $stored = $saveUploadedFile($uploadUserFile);
                if ($stored === null) {
                    return new JsonResponse([
                        'success' => false,
                        'message' => 'Не удалось сохранить файл. Попробуйте снова.',
                    ], Response::HTTP_INTERNAL_SERVER_ERROR);
                }
                // Skip if equals current main
                if ($memory->getMainPhoto() !== null && $stored === $memory->getMainPhoto()) {
                    continue;
                }
                $photoArhive = new PhotoArhive();
                $photoArhive->setPhoto($stored);
                $photoArhive->setMemory($memory);
                $this->entityManager->persist($photoArhive);
                $archiveCount++;
                if ($firstArchiveEntity === null) {
                    $firstArchiveEntity = $photoArhive;
                }
            }

        } else { // auto (backward compatibility) - also check limit here if main photo exists
            // If we have a main photo and are uploading more files, check limit
            if ($memory->getMainPhoto() !== null) {
                $memory->getPhotoArhive()->count(); // Force load collection
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
                // NO ADMIN EXCEPTION - everyone pays for more than 5 images
                
                $validUploadFiles = array_filter($uploadFiles, function($file) {
                    return $file instanceof UploadedFile;
                });
                $filesToUpload = count($validUploadFiles);

                // Everyone must pay (no admin exception)
                if (!$isExtended) {
                    $newTotal = $currentArchiveCount + $filesToUpload;
                    if ($newTotal > $freeLimit) {
                        $price = (float) $this->params->get('photo_extension_price', 500);
                        $this->logger->warning('Photo limit reached in auto mode', [
                            'memoryId' => $memory->getId(),
                            'currentArchiveCount' => $currentArchiveCount,
                            'uploading' => $filesToUpload,
                            'newTotal' => $newTotal,
                            'freeLimit' => $freeLimit,
                        ]);
                        return new JsonResponse([
                            'success' => false,
                            'limitReached' => true,
                            'currentCount' => $currentArchiveCount,
                            'freeLimit' => $freeLimit,
                            'price' => $price,
                            'message' => sprintf('Базовый план позволяет загрузить до %d фотографий. Для расширения доступа оплатите %d ₽.', $freeLimit, $price),
                        ], 402); // Payment Required
                    }
                }
            }

            foreach ($uploadFiles as $key => $uploadUserFile) {

            if (!$uploadUserFile instanceof UploadedFile) {
                $this->logger->warning('Skipped non-uploaded file entry during memory attachment processing.', [
                    'type' => is_object($uploadUserFile) ? $uploadUserFile::class : gettype($uploadUserFile),
                ]);

                continue;
            }

            $saveFileName = $saveUploadedFile($uploadUserFile);
            if ($saveFileName === null) {
                $this->logger->error('Failed to save file, continuing with other files', [
                    'filename' => $uploadUserFile->getClientOriginalName(),
                    'error' => $uploadUserFile->getError(),
                ]);
                // Continue with other files instead of failing completely
                continue;
            }

            // Record the first saved file name for fallback if avatar is missing
            if ($firstSavedFileName === null) {
                $firstSavedFileName = $saveFileName;
            }

            $originalFilename = pathinfo($uploadUserFile->getClientOriginalName(), PATHINFO_FILENAME);
            $isAvatar = ($originalFilename === 'Avatar_@_6226922');

            if ($isAvatar) {
                $memory->setMainPhoto($saveFileName);
                $mainSet = true;
                $avatarFound = true;
                // Ensure no archive entries keep the same file as main
                foreach ($memory->getPhotoArhive() as $existing) {
                    if ($existing->getPhoto() === $saveFileName) {
                        $memory->removePhotoArhive($existing);
                        $this->entityManager->remove($existing);
                        $archiveCount = max(0, $archiveCount - 1);
                    }
                }
            } else {
                $photoArhive = new PhotoArhive();
                $photoArhive->setPhoto($saveFileName);
                $photoArhive->setMemory($memory);
                $this->entityManager->persist($photoArhive);
                $archiveCount++;
                if ($firstArchiveEntity === null) {
                    $firstArchiveEntity = $photoArhive;
                }
            }

            }
        }

        // Fallback (auto mode only): if no avatar provided but files were uploaded, promote the first file to main
        if ($mode === 'auto' && !$avatarFound && !$mainSet && $firstSavedFileName !== null && $memory->getMainPhoto() === null) {
            $memory->setMainPhoto($firstSavedFileName);
            $mainSet = true;
            // Remove the first archive entry if it exists, since it is now the main photo
            if ($firstArchiveEntity !== null) {
                $memory->removePhotoArhive($firstArchiveEntity);
                $this->entityManager->remove($firstArchiveEntity);
                $archiveCount = max(0, $archiveCount - 1);
            }
        }

        $this->logger->info('Memory upload processed', [
            'memoryId' => $memory->getId(),
            'totalFilesReceived' => $totalFiles,
            'mainPhotoSet' => $mainSet,
            'archiveFilesAdded' => $archiveCount,
            'avatarFound' => $avatarFound,
            'mode' => $mode,
        ]);

        return true;
    }


    public function deleteAttachment(Memory $memory): void
    {

        $mainPhoto = $memory->getMainPhoto();

        if ($mainPhoto) {
            $filePath = $this->params->get('files_directory') . '/' . $mainPhoto;
            // delete old file if it exists
            if (file_exists($filePath)) {
                unlink($filePath);
            }
            //delete DB record
            $memory->setMainPhoto(null);
        }

        $photoArhives = $memory->getPhotoArhive();

        if (count($photoArhives) >= 1) {
            foreach ($photoArhives as $photoArhive) {
                $oldFilePath = $this->params->get('files_directory') . '/' . $photoArhive->getPhoto();
                // delete old file if it exists
                if (file_exists($oldFilePath)) {
                    unlink($oldFilePath);
                }

                //delete DB record
                $memory->removePhotoArhive($photoArhive);
                $this->entityManager->remove($photoArhive);
            }

        }
        //$this->entityManager->flush();

    }


    public function getFileFormat(string $fileName): string
    {
        return pathinfo($fileName, PATHINFO_EXTENSION);
    }


    public function getMainPhotoForEdit(string $mainPhoto): array
    {
        $files = array();

        //foreach ($todoAttachments as $key => $todoAttachment) {
        $file = $this->params->get('files_directory') . '/' . $mainPhoto;

        $files[] = [
            'fileFormat' => $this->getMIMEType($file),
            'fileSize' => file_exists($file) ? $this->fileSizeConvert(filesize($file)) : '0 B',
            'fileContent' => $this->getBaseFromFile($file),
            'fileName' => preg_replace('/-[^-]*$/', '', $mainPhoto)
        ];
        //  }

        return $files;

    }

    public function getPhotoArhiveForEdit(Collection $photoArhives): array
    {
        $files = array();

        foreach ($photoArhives as $key => $photoArhive) {
            $file = $this->params->get('files_directory') . '/' . $photoArhive->getPhoto();

            $files[] = [
                'fileFormat' => $this->getMIMEType($file),
                'fileSize' => file_exists($file) ? $this->fileSizeConvert(filesize($file)) : '0 B',
                'fileContent' => $this->getBaseFromFile($file),
                'fileName' => preg_replace('/-[^-]*$/', '', $photoArhive->getPhoto())
            ];
        }

        return $files;
    }

    public
    function getMIMEType(string $fileName): bool|string
    {
        if (empty($fileName) || !file_exists($fileName)) {
            return 'application/octet-stream'; // fallback
        }
        return mime_content_type($fileName);
    }

    public
    function fileSizeConvert($bytes): string
    {
        $bytes = floatval($bytes);
        $arBytes = array(
            0 => array(
                "UNIT" => "tb",
                "VALUE" => pow(1024, 4)
            ),
            1 => array(
                "UNIT" => "gb",
                "VALUE" => pow(1024, 3)
            ),
            2 => array(
                "UNIT" => "mb",
                "VALUE" => pow(1024, 2)
            ),
            3 => array(
                "UNIT" => "kb",
                "VALUE" => 1024
            ),
            4 => array(
                "UNIT" => "b",
                "VALUE" => 1
            ),
        );

        foreach ($arBytes as $arItem) {
            if ($bytes >= $arItem["VALUE"]) {
                $result = $bytes / $arItem["VALUE"];
                $result = str_replace(".", ",", strval(round($result, 2))) . " " . $arItem["UNIT"];
                break;
            }
        }
        return $result;
    }

    public
    function getBaseFromFile(string $file): string
    {
        if (empty($file) || !file_exists($file)) {
            return ''; // return empty string for missing files
        }
        return base64_encode(file_get_contents($file));
    }


}
