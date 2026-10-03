<?php
/*
Gibbon: the flexible, open school platform
Founded by Ross Parker at ICHK Secondary. Built by Ross Parker, Sandra Kuipers and the Gibbon community (https://gibbonedu.org/about/)
Copyright © 2010, Gibbon Foundation
Gibbon™, Gibbon Education Ltd. (Hong Kong)

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program. If not, see <http://www.gnu.org/licenses/>.
*/

use Gibbon\FileUploader;
use Gibbon\Domain\System\SettingGateway;
use Gibbon\Contracts\Filesystem\FileHandler;
use Gibbon\Module\Stream\Domain\PostGateway;
use Gibbon\Module\Stream\Domain\PostTagGateway;
use Gibbon\Module\Stream\Domain\PostAttachmentGateway;
use Gibbon\Module\Stream\Domain\CategoryGateway;
use Gibbon\Data\Validator;

$_POST['address'] = '/modules/Stream/stream_postProcess.php';

require_once '../../gibbon.php';

$source = $_POST['source'] ?? '';
$category = $_POST['category'] ?? '';
$streamCategoryID = $_POST['streamCategoryID'] ?? '';
$URL = $source == 'stream'
    ? $session->get('absoluteURL').'/index.php?q=/modules/Stream/stream.php&category='.$category.'&streamCategoryID='.$streamCategoryID
    : $session->get('absoluteURL').'/index.php?q=/modules/Stream/posts_manage_add.php';

if (isActionAccessible($guid, $connection2, '/modules/Stream/posts_manage_add.php') == false) {
    $URL .= '&return=error0';
    header("Location: {$URL}");
    exit;
} else {
    // Proceed!
    $postGateway = $container->get(PostGateway::class);
    $postTagGateway = $container->get(PostTagGateway::class);
    $postAttachmentGateway = $container->get(PostAttachmentGateway::class);
    $categoryGateway = $container->get(CategoryGateway::class);

    $partialFail = false;

    // Sanitize the whole $_POST array
    $_POST = $container->get(Validator::class)->sanitize($_POST);

    $data = [
        'gibbonSchoolYearID'    => $session->get('gibbonSchoolYearID'),
        'gibbonPersonID'        => $session->get('gibbonPersonID'),
        'post'                  => $_POST['post'] ?? '',
        'streamCategoryIDList'  => $_POST['streamCategoryIDList'] ?? null,
        'timestamp'             => date('Y-m-d H:i:s'),
    ];

    $data['streamCategoryIDList'] = is_array($data['streamCategoryIDList'])
        ? implode(',', $data['streamCategoryIDList'])
        : $data['streamCategoryIDList'];

    // Validate the required values are present
    if (empty($data['post'])) {
        $URL .= '&return=error1';
        header("Location: {$URL}");
        exit;
    }

    // Create the post
    $streamPostID = $postGateway->insert($data);

    // Auto-detect tags used in this post
    $matches = [];
    if (preg_match_all('/[#]+([\w]+)/iu', $data['post'], $matches)) {
        foreach ($matches[1] as $tag) {
            $data = [
                'streamPostID' => $streamPostID,
                'tag' => $tag,
            ];

            $postTagGateway->insertAndUpdate($data, $data);
        }
    }

    // Handle file upload for multiple attachments, including resizing images & generating thumbnails
    if (!empty($_FILES['attachments']['tmp_name'][0])) {
        $fileUploader = new FileUploader($pdo, $session);
        $absolutePath = $session->get('absolutePath');
        $maxImageSize = $container->get(SettingGateway::class)->getSettingByScope('Stream', 'maxImageSize');

        foreach ($_FILES['attachments']['name'] as $index => $name) {
            $file = array_combine(array_keys($_FILES['attachments']), array_column($_FILES['attachments'], $index));
            $attachment = $fileUploader->uploadAndResizeImage($file, 'streamPhoto', $maxImageSize, 90);

            if (!empty($attachment)) {
                $fileMetaData = $fileUploader->getFileMetaData($attachment);
                
                $thumbPath = $absolutePath.'/'.str_replace('streamPhoto', 'streamThumb', $attachment);
                $thumbnail = $fileUploader->resizeImage($absolutePath.'/'.$attachment, $thumbPath, 650);

                $data = [
                    'streamPostID' => $streamPostID,
                    'attachment'   => $attachment,
                    'thumbnail'    => str_replace($absolutePath.'/', '', $thumbnail),
                    'type'         => 'Image',
                ];

                $streamPostAttachmentID = $postAttachmentGateway->insert($data);
                
                // Record file tracking
                if (!empty($fileMetaData) && !empty($streamPostAttachmentID)) {
                    $fileHandler = $container->get(FileHandler::class);
                    $gibbonFileID = $fileHandler->recordFileUpload($fileMetaData, 'streamPostAttachment', $streamPostAttachmentID, 'attachment');
                    if (empty($gibbonFileID)) {
                        $partialFail = true;
                    }

                    $thumbRelative = is_string($thumbnail) ? str_replace($absolutePath.'/', '', $thumbnail) : '';
                    $thumbMeta = $thumbRelative !== '' ? $fileUploader->getFileMetaData($thumbRelative) : null;
                    if (!empty($thumbMeta) && empty($fileHandler->recordFileUpload($thumbMeta, 'streamPostAttachment', $streamPostAttachmentID, 'thumbnail'))) {
                        $partialFail = true;
                    }
                }
            } else {
                $partialFail = true;
            }
        }
    }

    $URL .= $partialFail
        ? "&return=warning1"
        : "&return=success0&editID=$streamPostID";

    header("Location: {$URL}");
}
