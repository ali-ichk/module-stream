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
use Gibbon\Module\Stream\Domain\PostGateway;
use Gibbon\Module\Stream\Domain\PostTagGateway;
use Gibbon\Module\Stream\Domain\PostAttachmentGateway;
use Gibbon\Domain\System\SettingGateway;
use Gibbon\Contracts\Filesystem\FileHandler;
use Gibbon\Data\Validator;

require_once '../../gibbon.php';

$streamPostID = $_POST['streamPostID'] ?? '';

$URL = $session->get('absoluteURL').'/index.php?q=/modules/Stream/posts_manage_edit.php&streamPostID='.$streamPostID;

if (isActionAccessible($guid, $connection2, '/modules/Stream/posts_manage_edit.php') == false) {
    $URL .= '&return=error0';
    header("Location: {$URL}");
    exit;
} else {

    // Proceed!
    $postGateway = $container->get(PostGateway::class);
    $postTagGateway = $container->get(PostTagGateway::class);
    $postAttachmentGateway = $container->get(PostAttachmentGateway::class);
    $partialFail = false;

    // Sanitize the whole $_POST array
    $_POST = $container->get(Validator::class)->sanitize($_POST);

    $data = [
        'post' => $_POST['post'] ?? '',
        'streamCategoryIDList'  => (!empty($_POST['streamCategoryIDList']) && (is_array($_POST['streamCategoryIDList'])) ? implode(",", $_POST['streamCategoryIDList']) : null)
    ];

    // Validate the required values are present
    if (empty($streamPostID)) {
        $URL .= '&return=error1';
        header("Location: {$URL}");
        exit;
    }

    // Validate the database relationships exist
    if (!$postGateway->exists($streamPostID)) {
        $URL .= '&return=error2';
        header("Location: {$URL}");
        exit;
    }

    // Update the record
    $updated = $postGateway->update($streamPostID, $data);

    // Auto-detect tags used in this post
    $matches = [];
    if (preg_match_all('/[#]+([\w]+)/iu', $data['post'], $matches)) {
        $postTagGateway->deleteWhere(['streamPostID' => $streamPostID]);

        foreach ($matches[1] as $tag) {
            $data = [
                'streamPostID' => $streamPostID,
                'tag' => $tag,
            ];

            $postTagGateway->insert($data);
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

    $URL .= !$updated
        ? "&return=error2"
        : "&return=success0";

    header("Location: {$URL}");
}
