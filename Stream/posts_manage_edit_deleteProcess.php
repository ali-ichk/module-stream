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

use Gibbon\Contracts\Filesystem\FileHandler;
use Gibbon\Module\Stream\Domain\PostAttachmentGateway;

$_POST['address'] = '/modules/Stream/posts_manage_edit.php';

require_once '../../gibbon.php';

$streamPostID = $_GET['streamPostID'] ?? '';
$streamPostAttachmentID = $_GET['streamPostAttachmentID'] ?? '';
$URL = $session->get('absoluteURL').'/index.php?q=/modules/Stream/posts_manage_edit.php&streamPostID='.$streamPostID;

if (isActionAccessible($guid, $connection2, '/modules/Stream/posts_manage_edit.php') == false) {
    $URL .= '&return=error0';
    header("Location: {$URL}");
    exit;
} else {
    // Proceed!
    $postAttachmentGateway = $container->get(PostAttachmentGateway::class); 
    // Validate the required values are present
    if (empty($streamPostID) || empty($streamPostAttachmentID)) {
        $URL .= '&return=error1';
        header("Location: {$URL}");
        exit;
    }

    // Validate the database relationships exist
    $attachment = $postAttachmentGateway->getByID($streamPostAttachmentID);
    if (empty($attachment)) {
        $URL .= '&return=error1';
        header("Location: {$URL}");
        exit;
    }

    $fileHandler = $container->get(FileHandler::class);
    $fileHandler->deleteFile('streamPostAttachment', $streamPostAttachmentID, 'attachment');
    $fileHandler->deleteFile('streamPostAttachment', $streamPostAttachmentID, 'thumbnail');

    // Delete the record
    $deleted = $postAttachmentGateway->delete($streamPostAttachmentID);

    $URL .= !$deleted
        ? "&return=warning1"
        : "&return=success0";

    header("Location: {$URL}");
}
