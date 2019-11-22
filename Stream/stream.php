<?php
/*
Gibbon, Flexible & Open School System
Copyright (C) 2010, Ross Parker

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

use Gibbon\Forms\Form;
use Gibbon\Services\Format;
use Gibbon\Domain\System\SettingGateway;
use Gibbon\Module\Stream\Domain\PostGateway;
use Gibbon\Module\Stream\Domain\PostTagGateway;
use Gibbon\Module\Stream\Domain\PostAttachmentGateway;
use Gibbon\Module\Stream\Domain\CategoryGateway;
use Gibbon\Module\Stream\Domain\CategoryViewedGateway;

if (isActionAccessible($guid, $connection2, '/modules/Stream/stream.php') == false) {
    // Access denied
    $page->addError(__('You do not have access to this action.'));
} else {
    // Proceed!
    $urlParams = [
        'streamCategoryID' => $_GET['streamCategoryID'] ?? '',
        'category' => $_GET['category'] ?? '',
        'tag'      => $_GET['tag'] ?? '',
        'user'     => $_GET['user'] ?? '',
    ];

    $page->scripts->add('magnific', 'modules/Stream/js/magnific/jquery.magnific-popup.min.js');
    $page->stylesheets->add('magnific', 'modules/Stream/js/magnific/magnific-popup.css');

    $page->breadcrumbs
        ->add(__m('View Stream'), 'stream.php');

    if (!empty($urlParams['category'])) {
        $page->breadcrumbs->add(__('Category').': '.$urlParams['category']);
    } else if (!empty($urlParams['tag']) || !empty($urlParams['user'])) {
        $page->breadcrumbs->add(__('Viewing {filter}', [
            'filter' => !empty($urlParams['user']) ? $urlParams['user'] : '#' . $urlParams['tag']
        ]));
    }

    if (isset($_GET['return'])) {
        returnProcess($guid, $_GET['return'], null, null);
    }

    // QUERY
    $postGateway = $container->get(PostGateway::class);
    $categoryGateway = $container->get(CategoryGateway::class);
    $categoryViewedGateway = $container->get(CategoryViewedGateway::class);
    $gibbonSchoolYearID = $gibbon->session->get('gibbonSchoolYearID');

    $criteria = $postGateway->newQueryCriteria(true)
        ->sortBy(['timestamp'], 'DESC')
        ->filterBy('category', $urlParams['streamCategoryID'])
        ->filterBy('tag', $urlParams['tag'])
        ->filterBy('user', $urlParams['user'])
        ->fromPOST();

    $yesterday = DateTime::createFromFormat('Y-m-d H:i:s', 'Yesterday');
    $viewed = $categoryViewedGateway->selectBy(['gibbonPersonID' => $gibbon->session->get('gibbonPersonID'), 'streamCategoryID' => $urlParams['streamCategoryID']])->fetch();

    $categories = $categoryGateway->selectViewableCategoriesByRole($gibbon->session->get('gibbonRoleIDCurrent'), $viewed['timestamp'] ?? $yesterday)->fetchGroupedUnique();
    if (!empty($categories)) {
        $categories = array_merge([0 => ['name' => __('All'), 'streamCategoryID' => 0]], $categories);
    }

    if (!empty($urlParams['streamCategoryID']) && empty($categories[$urlParams['streamCategoryID']])) {
        $page->addError(__('You do not have access to this action.'));
        return;
    }

    if (!empty($urlParams['streamCategoryID'])) {
        $data = [
            'gibbonPersonID' => $gibbon->session->get('gibbonPersonID'),
            'streamCategoryID' => $urlParams['streamCategoryID'],
            'timestamp' => date('Y-m-d H:i:s'),
        ];
        $categoryViewedGateway->insertAndUpdate($data, $data);
    }

    $stream = $postGateway->queryPostsBySchoolYear($criteria, $gibbonSchoolYearID, null, $gibbon->session->get('gibbonRoleIDCurrent'));

    // Join a set of attachment data per post
    $streamPosts = $stream->getColumn('streamPostID');
    $attachments = $container->get(PostAttachmentGateway::class)->selectAttachmentsByPost($streamPosts)->fetchGrouped();
    $stream->joinColumn('streamPostID', 'attachments', $attachments);

    // Auto-link hashtags
    $stream = array_map(function ($item) {
        $item['post'] = preg_replace_callback('/([#]+)([\w]+)/iu', function ($matches) {
            return Format::link('./index.php?q=/modules/Stream/stream.php&tag=' . $matches[2], $matches[1] . $matches[2]);
        }, $item['post']);

        return $item;
    }, $stream->toArray());

    // RENDER STREAM
    echo $page->fetchFromTemplate('stream.twig.html', [
        'stream' => $stream,
        'categories' => $categories,
        'currentCategory' => $urlParams['streamCategoryID'],
    ]);

    $categoryGateway = $container->get(CategoryGateway::class);
    $categories = $categoryGateway->selectPostableCategoriesByRole($gibbon->session->get('gibbonRoleIDCurrent'))->fetchKeyPair();

    // NEW POST
    // Ensure user has access to post in this category
    if (!empty($categories) && (empty($urlParams['streamCategoryID']) || !empty($categories[$urlParams['streamCategoryID']]))) {
        $form = Form::create('block', $gibbon->session->get('absoluteURL').'/modules/Stream/posts_manage_addProcess.php');
        $form->addHiddenValue('address', $gibbon->session->get('address'));
        $form->addHiddenValue('source', 'stream');
        $form->addHiddenValue('streamCategoryID', $urlParams['streamCategoryID']);

        $postLength = $container->get(SettingGateway::class)->getSettingByScope('Stream', 'postLength');
        $col = $form->addRow()->addColumn();
            $col->addTextArea('post')->required()->setRows(6)->addClass('font-sans text-sm')->maxLength($postLength);

        $row = $form->addRow()->addDetails()->summary(__('Add Photos'));
            $row->addFileUpload('attachments')->accepts('.jpg,.jpeg,.gif,.png')->uploadMultiple(true);

        // Categories
        if (!empty($urlParams['streamCategoryID'])) {
            $form->addHiddenValue('streamCategoryIDList', $urlParams['streamCategoryID']);
        } else if (!empty($categories)) {
            $row = $form->addRow()->addDetails()->summary(__('Categories'));
                $row->addCheckbox('streamCategoryIDList')->fromArray($categories);
        }

        $row = $form->addRow()->addSubmit(__('Post'));

        $_SESSION[$guid]['sidebarExtra'] .= '<h5 class="mt-4 mb-2 text-xs pb-0 ">'.__('New Post').'</h5>';
        $_SESSION[$guid]['sidebarExtra'] .= $form->getOutput();
    }

    // RECENT TAGS
    $tags = $container->get(PostTagGateway::class)->selectRecentTagsBySchoolYear($gibbonSchoolYearID)->fetchAll(\PDO::FETCH_COLUMN, 0);
    $_SESSION[$guid]['sidebarExtra'] .= $page->fetchFromTemplate('tags.twig.html', [
        'tags' => $tags,
    ]);
}
?>
<script>
    $(document).ready(function() {
        $('.image-container').magnificPopup({
            type: 'image',
            delegate: 'a',
            gallery: {
                enabled: true
            },
            image: {
                titleSrc: 'data-caption',
            }
        });
    });
</script>
